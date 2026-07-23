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
        'price',
        'gb',
        'days',
        'yield_val',
        'category',
        'product_type',
        'image_timestamp',
        'image_location'
    ];

    public function pricelist(): BelongsTo
    {
        return $this->belongsTo(Pricelist::class);
    }
}
