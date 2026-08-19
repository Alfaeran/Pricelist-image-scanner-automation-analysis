<?php

use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group.
|
*/

// ──────────────────────────────────────────────
// WhatsApp Webhook (No auth required)
// ──────────────────────────────────────────────
Route::prefix('whatsapp')->group(function () {
    // Webhook verification (GET) — used by Meta Cloud API handshake
    Route::get('/webhook', [WhatsAppWebhookController::class, 'verify'])
        ->name('whatsapp.webhook.verify');

    // Incoming messages (POST) — receives messages from Meta/Evolution
    Route::post('/webhook', [WhatsAppWebhookController::class, 'handle'])
        ->name('whatsapp.webhook.handle');
});

// ──────────────────────────────────────────────
// WhatsApp Dashboard API (Web Auth required)
// ──────────────────────────────────────────────
Route::middleware(['web', 'auth'])->prefix('whatsapp')->group(function () {
    // Connection status
    Route::get('/status', [WhatsAppWebhookController::class, 'connectionStatus'])
        ->name('whatsapp.status');

    // Stats for dashboard widgets
    Route::get('/stats', [WhatsAppWebhookController::class, 'stats'])
        ->name('whatsapp.stats');

    // Conversation list
    Route::get('/conversations', [WhatsAppWebhookController::class, 'conversations'])
        ->name('whatsapp.conversations');

    // Messages for a conversation
    Route::get('/conversations/{conversation}/messages', [WhatsAppWebhookController::class, 'conversationMessages'])
        ->name('whatsapp.conversations.messages');
});
