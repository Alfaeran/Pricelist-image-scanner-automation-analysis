<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$baselineData = ['exact' => [], 'fallback' => [], 'providers' => []];
$products = \App\Models\BaselineProduct::all();
foreach ($products as $product) {
    $providerStr = strtoupper(trim($product->provider));
    if (!in_array($providerStr, $baselineData['providers'])) {
        $baselineData['providers'][] = $providerStr;
    }

    $key = $providerStr . '_' . (float)$product->quota_s . '_' . $product->days;
    $baselineData['exact'][$key] = [
        'price' => $product->price,
        'name' => $product->package_name
    ];
    $fallbackKey = $providerStr . '_' . (float)$product->quota_s;
    if (!isset($baselineData['fallback'][$fallbackKey])) {
        $baselineData['fallback'][$fallbackKey] = [];
    }
    $baselineData['fallback'][$fallbackKey][] = [
        'price' => $product->price,
        'days' => $product->days
    ];
}

\App\Models\ExtractedPackage::chunk(100, function ($pkgs) use ($baselineData) {
    foreach ($pkgs as $pkg) {
        if (!isset($pkg->gb, $pkg->days, $pkg->price)) continue;
        
        $providerStr = strtoupper(trim($pkg->provider ?? 'UNKNOWN'));
        if ($providerStr == 'SF') $providerStr = 'SMARTFREN';
        elseif ($providerStr == 'TSEL') $providerStr = 'TELKOMSEL';
        elseif ($providerStr == '3ID') $providerStr = '3';
        elseif ($providerStr == 'BYU') $providerStr = 'BY.U';

        $key = $providerStr . '_' . (float)$pkg->gb . '_' . $pkg->days;
        $fallbackKey = $providerStr . '_' . (float)$pkg->gb;
        
        $isNewProduct = false;
        $isPriceChanged = false;
        $isDaysChanged = false;
        $baselinePrice = null;
        $baselineDays = null;

        if (in_array($providerStr, $baselineData['providers'])) {
            if (isset($baselineData['exact'][$key])) {
                $baselinePrice = $baselineData['exact'][$key]['price'];
                if ($pkg->price !== $baselinePrice) {
                    $isPriceChanged = true;
                }
            } elseif (isset($baselineData['fallback'][$fallbackKey])) {
                $fallbackMatch = $baselineData['fallback'][$fallbackKey][0];
                $isDaysChanged = true;
                $baselinePrice = $fallbackMatch['price'];
                $baselineDays = $fallbackMatch['days'];
                if ($pkg->price !== $baselinePrice) {
                    $isPriceChanged = true;
                }
            } else {
                $isNewProduct = true;
            }
        }

        $pkg->update([
            'is_new_product' => $isNewProduct,
            'is_price_changed' => $isPriceChanged,
            'is_days_changed' => $isDaysChanged,
            'baseline_price' => $baselinePrice,
            'baseline_days' => $baselineDays,
        ]);
    }
});
echo "Done backfilling with provider check.\n";
