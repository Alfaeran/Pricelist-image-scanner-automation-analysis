<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$query = \App\Models\ExtractedPackage::query()
    ->join('pricelists', 'extracted_packages.pricelist_id', '=', 'pricelists.id')
    ->where('pricelists.status', 'processed')
    ->where('extracted_packages.price', '>', 0)
    ->where('extracted_packages.gb', '>', 0)
    ->where('extracted_packages.yield_val', '>', 0);

$data = $query->selectRaw("
    DATE(COALESCE(extracted_packages.image_timestamp, pricelists.created_at)) as scan_date,
    extracted_packages.provider,
    ROUND(AVG(extracted_packages.price), 0) as avg_price,
    ROUND(AVG(extracted_packages.yield_val), 0) as avg_yield,
    COUNT(*) as count,
    ROUND(AVG(extracted_packages.gb), 1) as avg_gb
")
->groupBy('scan_date', 'extracted_packages.provider')
->orderBy('scan_date')
->get();

echo "Total records: " . $data->count() . "\n";
foreach($data as $row) {
    echo $row->scan_date . " | " . $row->provider . " | " . $row->count . "\n";
}
