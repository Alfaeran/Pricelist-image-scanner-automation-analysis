<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtractedPackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'pricelist_id',
        'provider',
        'package_name',
        'price',
        'gb',
        'days',
        'yield_val',
        'is_anomaly',
        'is_new_product',
        'is_price_changed',
        'is_days_changed',
        'baseline_price',
        'baseline_days',
        'category',
        'product_type',
        'image_timestamp',
        'image_location',
        'image_filename',
        // Without these three, mass assignment silently drops the values
        // ProcessPricelistJob passes to create(), and every location filter on
        // the trends endpoint matches nothing.
        'circle',
        'region',
        'branch',
    ];

    public function pricelist(): BelongsTo
    {
        return $this->belongsTo(Pricelist::class);
    }

    /**
     * Map a branch (kabupaten/kota) onto the circle/region hierarchy the
     * dashboard filters by.
     *
     * Lives on the model because both ingestion paths need it: the async image
     * job and the synchronous CSV import in ScannerController.
     *
     * ponytail: hard-coded Java lists, anything unlisted falls to Bali Nusra.
     * Move to a lookup table when a fourth region appears.
     */
    public static function locationDetails(?string $branch): array
    {
        if (!$branch) {
            return ['circle' => null, 'region' => null, 'branch' => null];
        }

        $centralJava = ['Semarang', 'Surakarta', 'Magelang', 'Salatiga', 'Tegal', 'Pekalongan', 'Cilacap', 'Banyumas', 'Purbalingga', 'Banjarnegara', 'Kebumen', 'Purworejo', 'Wonosobo', 'Boyolali', 'Klaten', 'Sukoharjo', 'Wonogiri', 'Karanganyar', 'Sragen', 'Grobogan', 'Blora', 'Rembang', 'Pati', 'Kudus', 'Jepara', 'Demak', 'Temanggung', 'Kendal', 'Batang', 'Pemalang', 'Brebes'];
        $eastJava = ['Surabaya', 'Malang', 'Kediri', 'Madiun', 'Mojokerto', 'Pasuruan', 'Probolinggo', 'Batu', 'Blitar', 'Bangkalan', 'Banyuwangi', 'Bojonegoro', 'Bondowoso', 'Gresik', 'Jember', 'Jombang', 'Lamongan', 'Lumajang', 'Magetan', 'Nganjuk', 'Ngawi', 'Pacitan', 'Pamekasan', 'Ponorogo', 'Sampang', 'Sidoarjo', 'Situbondo', 'Sumenep', 'Trenggalek', 'Tuban', 'Tulungagung'];

        $region = 'Bali Nusra';
        if (in_array($branch, $centralJava)) {
            $region = 'Central Java';
        } elseif (in_array($branch, $eastJava)) {
            $region = 'East Java';
        }

        return [
            'circle' => 'Java Bali Nusra',
            'region' => $region,
            'branch' => $branch,
        ];
    }
}
