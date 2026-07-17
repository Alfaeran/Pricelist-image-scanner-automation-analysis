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
        Schema::create('pricelists', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('status')->default('pending'); // pending, processed, failed
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('extracted_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pricelist_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->integer('price');
            $table->decimal('gb', 8, 2);
            $table->integer('days');
            $table->integer('yield_val');
            $table->string('category');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('extracted_packages');
        Schema::dropIfExists('pricelists');
    }
};
