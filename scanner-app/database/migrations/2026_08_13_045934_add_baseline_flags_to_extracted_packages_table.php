<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('extracted_packages', function (Blueprint $table) {
            $table->boolean('is_new_product')->default(false)->after('is_anomaly');
            $table->boolean('is_price_changed')->default(false)->after('is_new_product');
            $table->integer('baseline_price')->nullable()->after('is_price_changed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('extracted_packages', function (Blueprint $table) {
            $table->dropColumn(['is_new_product', 'is_price_changed', 'baseline_price']);
        });
    }
};
