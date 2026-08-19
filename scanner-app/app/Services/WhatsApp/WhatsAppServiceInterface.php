<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Request;

/**
 * WhatsApp Service Interface
 *
 * Abstraction layer for WhatsApp API providers.
 * Implementations: MetaCloudApiService, EvolutionApiService
 *
 * Switch driver via WHATSAPP_DRIVER env variable.
 */
interface WhatsAppServiceInterface
{
    /**
     * Send a text message to a WhatsApp number.
     */
    public function sendTextMessage(string $to, string $message): bool;

    /**
     * Send an image message with optional caption.
     */
    public function sendImageMessage(string $to, string $imageUrl, string $caption = ''): bool;

    /**
     * Send a document/file to a WhatsApp number.
     */
    public function sendDocument(string $to, string $documentUrl, string $filename, string $caption = ''): bool;

    /**
     * Download media from a message (image, document, etc).
     * Returns the local file path where media was saved.
     */
    public function downloadMedia(string $mediaId): string;

    /**
     * Verify incoming webhook request (used by Meta for challenge/response).
     */
    public function verifyWebhook(Request $request): mixed;

    /**
     * Parse incoming webhook payload into a normalized WhatsAppMessage DTO.
     * Returns null if the payload doesn't contain a processable message.
     */
    public function parseIncomingMessage(Request $request): ?WhatsAppMessage;

    /**
     * Check if the service connection is healthy/active.
     */
    public function isConnected(): bool;

    /**
     * Get connection status details (e.g., QR code data for Evolution API).
     */
    public function getConnectionStatus(): array;
}
