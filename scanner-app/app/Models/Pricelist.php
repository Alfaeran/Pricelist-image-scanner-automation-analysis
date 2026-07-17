<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pricelist extends Model
{
    use HasFactory;

    protected $fillable = [
        'filename',
        'status',
        'error_message',
        'performance_metrics'
    ];

    protected $casts = [
        'performance_metrics' => 'array',
    ];

    public function packages(): HasMany
    {
        return $this->hasMany(ExtractedPackage::class);
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }
}
