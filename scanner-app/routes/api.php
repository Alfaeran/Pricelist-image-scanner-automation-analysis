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
// 'web' brings the session and AutoAuthenticateDesktop, which signs in the
// local user. 'auth' is deliberately absent: there is no login route to
// redirect to any more, so leaving it here turns every call into a 500.
Route::middleware(['web'])->prefix('whatsapp')->group(function () {
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
