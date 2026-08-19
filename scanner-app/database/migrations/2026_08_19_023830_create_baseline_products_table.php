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
        Schema::create('baseline_products', function (Blueprint $table) {
            $table->id();
            $table->string('criteria')->nullable();
            $table->string('provider');
            $table->string('package_name');
            $table->string('rbp_vori')->nullable();
            $table->string('rbp_rebuy')->nullable();
            $table->string('rbp_inject')->nullable();
            $table->integer('price')->default(0);
            $table->decimal('quota_s', 8, 2)->default(0);
            $table->decimal('quota_e', 8, 2)->default(0);
            $table->decimal('quota_a', 8, 2)->default(0);
            $table->integer('days')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('baseline_products');
    }
};
