<?php
$baselineData = [];
$fullPath = base_path('List produk.csv');
if (file_exists($fullPath)) {
    if (($handle = fopen($fullPath, "r")) !== FALSE) {
        $isHeader = true;
        while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if ($isHeader) {
                $isHeader = false;
                continue;
            }
            if (count($row) < 11) continue;
            
            $provider = strtoupper(trim($row[1]));
            if (!$provider) continue;
            
            if ($provider == 'SF') $provider = 'SMARTFREN';
            elseif ($provider == 'TSEL') $provider = 'TELKOMSEL';
            elseif ($provider == '3ID') $provider = '3';
            elseif ($provider == 'BYU') $provider = 'BY.U';
            
            $priceStr = $row[6];
            if (empty(trim($priceStr))) {
                $priceStr = $row[4];
            }
            $price = (int) preg_replace('/[^\d]/', '', $priceStr ?? '');
            
            $gbStr = str_replace(',', '.', $row[7] ?? '');
            $gb = (float) preg_replace('/[^\d\.]/', '', $gbStr);
            
            $daysStr = $row[10] ?? '';
            if (strtolower(trim($daysStr)) === 'follow sim') {
                $days = 0;
            } else {
                $days = (int) preg_replace('/[^\d]/', '', $daysStr);
            }
            
            $key = $provider . '_' . $gb . '_' . $days;
            $baselineData[$key] = [
                'price' => $price,
                'name' => $row[2]
            ];
        }
        fclose($handle);
    }
}

$packages = App\Models\ExtractedPackage::all();
$updated = 0;
foreach ($packages as $pkg) {
    $providerStr = strtoupper(trim($pkg->provider ?? 'UNKNOWN'));
    if ($providerStr == 'SF') $providerStr = 'SMARTFREN';
    elseif ($providerStr == 'TSEL') $providerStr = 'TELKOMSEL';
    elseif ($providerStr == '3ID') $providerStr = '3';
    elseif ($providerStr == 'BYU') $providerStr = 'BY.U';

    $gb = (float) $pkg->gb;
    $days = (int) $pkg->days;
    $price = (int) $pkg->price;

    $baselineKey = $providerStr . '_' . $gb . '_' . $days;
    $isNewProduct = false;
    $isPriceChanged = false;
    $baselinePrice = null;

    if (isset($baselineData[$baselineKey])) {
        $baselinePrice = $baselineData[$baselineKey]['price'];
        if ($price !== $baselinePrice) {
            $isPriceChanged = true;
        }
    } else {
        $isNewProduct = true;
    }

    $pkg->is_new_product = $isNewProduct;
    $pkg->is_price_changed = $isPriceChanged;
    $pkg->baseline_price = $baselinePrice;
    $pkg->save();
    $updated++;
}
echo "Updated $updated packages.\n";
