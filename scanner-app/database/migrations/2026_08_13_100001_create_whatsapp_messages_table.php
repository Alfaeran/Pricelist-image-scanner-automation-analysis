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
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->string('direction', 10)->comment('incoming or outgoing');
            $table->string('message_type', 20)->default('text')->comment('text, image, document, audio, video');
            $table->text('content')->nullable();
            $table->string('media_url', 500)->nullable();
            $table->string('media_path', 500)->nullable()->comment('Local storage path');
            $table->string('wa_message_id', 100)->nullable()->index();
            $table->string('status', 20)->default('received')->comment('received, processing, sent, delivered, read, failed');
            $table->json('metadata')->nullable()->comment('Extra data: chart configs, processing results, etc.');
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
