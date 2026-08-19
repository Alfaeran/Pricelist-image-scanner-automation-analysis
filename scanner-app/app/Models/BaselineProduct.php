<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BaselineProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'criteria',
        'provider',
        'package_name',
        'rbp_vori',
        'rbp_rebuy',
        'rbp_inject',
        'price',
        'quota_s',
        'quota_e',
        'quota_a',
        'days'
    ];
}
