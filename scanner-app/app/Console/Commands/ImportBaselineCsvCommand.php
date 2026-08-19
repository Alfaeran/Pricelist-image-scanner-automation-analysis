<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\BaselineProduct;
use Illuminate\Support\Facades\DB;

class ImportBaselineCsvCommand extends Command
{
    protected $signature = 'baseline:import-csv';
    protected $description = 'Import baseline products from List produk.csv into database';

    public function handle()
    {
        $fullPath = base_path('List produk.csv');
        if (!file_exists($fullPath)) {
            $this->error("File List produk.csv tidak ditemukan.");
            return 1;
        }

        $this->info("Mengimpor data dari $fullPath...");
        
        if (($handle = fopen($fullPath, "r")) !== FALSE) {
            $isHeader = true;
            $count = 0;
            
            DB::beginTransaction();
            try {
                // Hapus data lama agar tidak duplikat jika dijalankan ulang
                BaselineProduct::truncate();
                
                while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    if ($isHeader) {
                        $isHeader = false;
                        continue;
                    }
                    if (count($row) < 11) continue;
                    
                    $criteria = $row[0] ?? null;
                    
                    $provider = strtoupper(trim($row[1]));
                    if (!$provider) continue;
                    if ($provider == 'SF') $provider = 'SMARTFREN';
                    elseif ($provider == 'TSEL') $provider = 'TELKOMSEL';
                    elseif ($provider == '3ID') $provider = '3';
                    elseif ($provider == 'BYU') $provider = 'BY.U';
                    
                    $packageName = $row[2] ?? '';
                    
                    $rbpVori = $row[3] ?? null;
                    $rbpRebuy = $row[4] ?? null;
                    $rbpInject = $row[5] ?? null;
                    
                    $priceStr = $row[6];
                    if (empty(trim($priceStr))) {
                        $priceStr = $row[4];
                    }
                    $price = (int) preg_replace('/[^\d]/', '', $priceStr ?? '');
                    
                    $quota_s = (float) preg_replace('/[^\d\.]/', '', str_replace(',', '.', $row[7] ?? ''));
                    $quota_e = (float) preg_replace('/[^\d\.]/', '', str_replace(',', '.', $row[8] ?? ''));
                    $quota_a = (float) preg_replace('/[^\d\.]/', '', str_replace(',', '.', $row[9] ?? ''));
                    
                    $daysStr = $row[10] ?? '';
                    if (strtolower(trim($daysStr)) === 'follow sim') {
                        $days = 0;
                    } else {
                        $days = (int) preg_replace('/[^\d]/', '', $daysStr);
                    }
                    
                    BaselineProduct::create([
                        'criteria' => $criteria,
                        'provider' => $provider,
                        'package_name' => $packageName,
                        'rbp_vori' => $rbpVori,
                        'rbp_rebuy' => $rbpRebuy,
                        'rbp_inject' => $rbpInject,
                        'price' => $price,
                        'quota_s' => $quota_s,
                        'quota_e' => $quota_e,
                        'quota_a' => $quota_a,
                        'days' => $days
                    ]);
                    $count++;
                }
                fclose($handle);
                DB::commit();
                $this->info("Berhasil mengimpor $count baris ke tabel baseline_products.");
            } catch (\Exception $e) {
                DB::rollBack();
                $this->error("Terjadi kesalahan: " . $e->getMessage());
                return 1;
            }
        }
        
        return 0;
    }
}
