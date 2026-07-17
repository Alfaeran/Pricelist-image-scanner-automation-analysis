<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'is_active',
        'cooldown_until',
        'usage_count',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'cooldown_until' => 'datetime',
    ];
}
