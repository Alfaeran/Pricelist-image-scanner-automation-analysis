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
            $table->boolean('is_days_changed')->default(false)->after('is_price_changed');
            $table->integer('baseline_days')->nullable()->after('baseline_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('extracted_packages', function (Blueprint $table) {
            $table->dropColumn(['is_days_changed', 'baseline_days']);
        });
    }
};
