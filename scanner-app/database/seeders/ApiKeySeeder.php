<?php

namespace Database\Seeders;

use App\Models\ApiKey;
use Illuminate\Database\Seeder;

class ApiKeySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        ApiKey::create([
            'key' => 'MASUKKAN_API_KEY_GEMINI_ANDA_DISINI',
            'is_active' => true,
        ]);
        
        ApiKey::create([
            'key' => 'MASUKKAN_API_KEY_CADANGAN_DISINI',
            'is_active' => true,
        ]);
    }
}
