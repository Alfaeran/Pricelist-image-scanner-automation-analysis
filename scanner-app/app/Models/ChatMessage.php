<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'pricelist_id',
        'role',
        'content',
        'chart_config',
        'attachments',
    ];

    protected $casts = [
        'chart_config' => 'array',
        'attachments' => 'array',
    ];
}
