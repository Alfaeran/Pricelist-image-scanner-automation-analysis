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
    ];

    public function pricelist(): BelongsTo
    {
        return $this->belongsTo(Pricelist::class);
    }
}
