<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LearnedPattern extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'mismatch_context',
        'rule_text',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
