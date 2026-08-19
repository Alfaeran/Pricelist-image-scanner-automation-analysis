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
            $table->string('circle')->nullable()->after('image_location');
            $table->string('region')->nullable()->after('circle');
            $table->string('branch')->nullable()->after('region');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('extracted_packages', function (Blueprint $table) {
            $table->dropColumn(['circle', 'region', 'branch']);
        });
    }
};
