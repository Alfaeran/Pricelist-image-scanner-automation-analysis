<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Evolution API Service (Self-hosted, Unofficial)
 *
 * Uses the open-source Evolution API for WhatsApp connectivity.
 * Requires: Docker container running Evolution API + QR code scan.
 *
 * Free, but carries risk of WhatsApp ban for commercial use.
 *
 * Config keys:
 * - WHATSAPP_EVOLUTION_URL: Base URL of Evolution API (e.g., http://localhost:8080)
 * - WHATSAPP_EVOLUTION_API_KEY: API key for authentication
 * - WHATSAPP_EVOLUTION_INSTANCE: Instance name (e.g., scanner-bot)
 */
class EvolutionApiService implements WhatsAppServiceInterface
{
    private string $baseUrl;
    private string $apiKey;
    private string $instance;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.whatsapp.evolution.url', 'http://localhost:8080'), '/');
        $this->apiKey = config('services.whatsapp.evolution.api_key', '');
        $this->instance = config('services.whatsapp.evolution.instance', 'scanner-bot');
    }

    /**
     * @inheritDoc
     */
    public function sendTextMessage(string $to, string $message): bool
    {
        try {
            $response = Http::withHeaders([
                'apikey' => $this->apiKey,
            ])->post("{$this->baseUrl}/message/sendText/{$this->instance}", [
                'number' => $this->normalizeNumber($to),
                'text' => $message,
            ]);

            if ($response->successful()) {
                $sentId = $response->json('key.id') ?? $response->json('id');
                if ($sentId) {
                    \Illuminate\Support\Facades\Cache::put("bot_sent_msg_{$sentId}", true, 3600);
                }
                Log::info("Evolution API: Text sent to {$to}");
                return true;
            }

            Log::error("Evolution API send failed: " . $response->body());
            return false;
        } catch (\Exception $e) {
            Log::error("Evolution API send error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function sendImageMessage(string $to, string $imageUrl, string $caption = ''): bool
    {
        try {
            $response = Http::withHeaders([
                'apikey' => $this->apiKey,
            ])->post("{$this->baseUrl}/message/sendMedia/{$this->instance}", [
                'number' => $this->normalizeNumber($to),
                'mediatype' => 'image',
                'media' => $imageUrl,
                'caption' => $caption,
            ]);

            if ($response->successful()) {
                $sentId = $response->json('key.id') ?? $response->json('id');
                if ($sentId) {
                    \Illuminate\Support\Facades\Cache::put("bot_sent_msg_{$sentId}", true, 3600);
                }
                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error("Evolution API image send error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function sendDocument(string $to, string $documentUrl, string $filename, string $caption = ''): bool
    {
        try {
            $response = Http::withHeaders([
                'apikey' => $this->apiKey,
            ])->post("{$this->baseUrl}/message/sendMedia/{$this->instance}", [
                'number' => $this->normalizeNumber($to),
                'mediatype' => 'document',
                'media' => $documentUrl,
                'fileName' => $filename,
                'caption' => $caption,
            ]);

            if ($response->successful()) {
                $sentId = $response->json('key.id') ?? $response->json('id');
                if ($sentId) {
                    \Illuminate\Support\Facades\Cache::put("bot_sent_msg_{$sentId}", true, 3600);
                }
                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::error("Evolution API document send error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function downloadMedia(string $mediaId): string
    {
        try {
            // Evolution API provides media as base64 in webhook or via download endpoint
            $response = Http::withHeaders([
                'apikey' => $this->apiKey,
            ])->post("{$this->baseUrl}/chat/getBase64FromMediaMessage/{$this->instance}", [
                'message' => ['key' => ['id' => $mediaId]],
                'convertToMp4' => false,
            ]);

            if (!$response->successful()) {
                throw new \RuntimeException("Failed to download media: " . $response->body());
            }

            $data = $response->json();
            $base64 = $data['base64'] ?? '';
            $mimeType = $data['mimetype'] ?? 'image/jpeg';

            if (empty($base64)) {
                throw new \RuntimeException("Empty media content received");
            }

            $extension = match (true) {
                str_contains($mimeType, 'jpeg') || str_contains($mimeType, 'jpg') => 'jpg',
                str_contains($mimeType, 'png') => 'png',
                str_contains($mimeType, 'webp') => 'webp',
                str_contains($mimeType, 'pdf') => 'pdf',
                str_contains($mimeType, 'zip') || str_contains($mimeType, 'compressed') || str_contains($mimeType, 'octet-stream') => 'zip',
                default => 'bin',
            };

            // Magic bytes check for ZIP files (50 4B 03 04)
            $decoded = base64_decode($base64);
            if (str_starts_with($decoded, "PK\x03\x04")) {
                $extension = 'zip';
            }

            $filename = 'whatsapp/' . date('Y-m-d') . '/' . uniqid('wa_') . '.' . $extension;
            Storage::disk('public')->put($filename, $decoded);

            return $filename;
        } catch (\Exception $e) {
            Log::error("Evolution API media download error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function verifyWebhook(Request $request): mixed
    {
        // Evolution API doesn't use challenge/response verification.
        // It uses API key in headers instead.
        return response('OK', 200);
    }

    /**
     * @inheritDoc
     */
    public function parseIncomingMessage(Request $request): ?WhatsAppMessage
    {
        $payload = $request->all();

        // Evolution API sends different event types
        $event = $payload['event'] ?? $request->header('x-event') ?? '';

        // We only care about incoming messages
        if (!in_array($event, ['messages.upsert', 'MESSAGES_UPSERT', ''])) {
            return null;
        }

        $data = $payload['data'] ?? $payload;

        // Handle the nested message structure
        $message = $data['message'] ?? $data;
        $key = $data['key'] ?? [];
        $pushName = $data['pushName'] ?? null;

        // Skip outgoing messages sent by bot (tracked in Cache)
        $messageId = $key['id'] ?? '';
        if (($key['fromMe'] ?? false) === true) {
            // Skip ONLY if this message ID was generated & sent by our bot
            if (!empty($messageId) && \Illuminate\Support\Facades\Cache::has("bot_sent_msg_{$messageId}")) {
                return null;
            }
        }

        $remoteJid = $key['remoteJid'] ?? '';

        // Ignore WhatsApp Group messages (@g.us)
        if (str_contains($remoteJid, '@g.us')) {
            return null;
        }

        $from = $this->extractPhoneNumber($remoteJid);
        if (empty($from)) return null;

        // Check Whitelist Allowed Phone Numbers filter from .env (WHATSAPP_ALLOWED_NUMBERS)
        $allowedEnv = env('WHATSAPP_ALLOWED_NUMBERS');
        if (!empty($allowedEnv) && trim($allowedEnv) !== '*') {
            $allowedList = array_map('trim', explode(',', $allowedEnv));
            $allowedNumbers = array_map(function($num) {
                $num = preg_replace('/[^0-9]/', '', $num);
                if (str_starts_with($num, '0')) {
                    $num = '62' . substr($num, 1);
                }
                return $num;
            }, $allowedList);

            if (!in_array($from, $allowedNumbers)) {
                Log::info("Evolution API: Skipped message from non-whitelisted number: {$from}");
                return null;
            }
        }

        // Determine message type and content
        $type = 'text';
        $text = '';
        $mediaId = $key['id'] ?? null;
        $mediaUrl = null;
        $mediaMimeType = null;
        $mediaFilename = null;
        $caption = null;

        if (isset($message['conversation'])) {
            $type = 'text';
            $text = $message['conversation'];
        } elseif (isset($message['extendedTextMessage'])) {
            $type = 'text';
            $text = $message['extendedTextMessage']['text'] ?? '';
        } elseif (isset($message['imageMessage'])) {
            $type = 'image';
            $mediaMimeType = $message['imageMessage']['mimetype'] ?? 'image/jpeg';
            $mediaUrl = $message['imageMessage']['url'] ?? null;
            $caption = $message['imageMessage']['caption'] ?? null;
        } elseif (isset($message['documentMessage'])) {
            $type = 'document';
            $mediaMimeType = $message['documentMessage']['mimetype'] ?? null;
            $mediaUrl = $message['documentMessage']['url'] ?? null;
            $mediaFilename = $message['documentMessage']['fileName'] ?? null;
            $caption = $message['documentMessage']['caption'] ?? null;
        } elseif (isset($message['audioMessage'])) {
            $type = 'audio';
            $mediaMimeType = $message['audioMessage']['mimetype'] ?? 'audio/ogg';
        } elseif (isset($message['videoMessage'])) {
            $type = 'video';
            $mediaMimeType = $message['videoMessage']['mimetype'] ?? 'video/mp4';
            $caption = $message['videoMessage']['caption'] ?? null;
        } else {
            // Unsupported message type
            return null;
        }

        return new WhatsAppMessage(
            from: $from,
            text: $text,
            type: $type,
            mediaId: $mediaId,
            mediaUrl: $mediaUrl,
            mediaMimeType: $mediaMimeType,
            mediaFilename: $mediaFilename,
            caption: $caption,
            messageId: $key['id'] ?? null,
            senderName: $pushName,
            timestamp: isset($data['messageTimestamp']) ? (int) $data['messageTimestamp'] : null,
            rawPayload: $payload,
        );
    }

    /**
     * @inheritDoc
     */
    public function isConnected(): bool
    {
        try {
            $response = Http::withHeaders([
                'apikey' => $this->apiKey,
            ])->get("{$this->baseUrl}/instance/connectionState/{$this->instance}");

            if ($response->successful()) {
                $state = $response->json('instance.state') ?? $response->json('state');
                return $state === 'open';
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getConnectionStatus(): array
    {
        $status = [
            'driver' => 'evolution',
            'connected' => false,
            'instance' => $this->instance,
            'configured' => !empty($this->apiKey) && !empty($this->baseUrl),
            'qr_code' => null,
        ];

        try {
            $connResponse = Http::timeout(3)->withHeaders([
                'apikey' => $this->apiKey,
            ])->get("{$this->baseUrl}/instance/connectionState/{$this->instance}");

            if ($connResponse->successful()) {
                $state = $connResponse->json('instance.state') ?? $connResponse->json('state');
                $status['connected'] = ($state === 'open');
                $status['state'] = $state;
            }

            // Only request QR code if disconnected and we don't have a fresh QR code in cache
            if (!$status['connected']) {
                $status['qr_code'] = \Illuminate\Support\Facades\Cache::get("evolution_qr_{$this->instance}");

                if (!$status['qr_code'] && !\Illuminate\Support\Facades\Cache::has("evolution_qr_lock_{$this->instance}")) {
                    // Lock for 10 seconds to allow Baileys WebSocket to generate QR without interruption
                    \Illuminate\Support\Facades\Cache::put("evolution_qr_lock_{$this->instance}", true, 10);

                    $qrResponse = Http::timeout(5)->withHeaders([
                        'apikey' => $this->apiKey,
                    ])->get("{$this->baseUrl}/instance/connect/{$this->instance}");

                    if ($qrResponse->successful()) {
                        $json = $qrResponse->json();
                        $qr = $json['base64'] 
                            ?? $json['qrcode']['base64'] 
                            ?? $json['qrcode']['code']
                            ?? $json['code']
                            ?? null;

                        if ($qr) {
                            $status['qr_code'] = $qr;
                            \Illuminate\Support\Facades\Cache::put("evolution_qr_{$this->instance}", $qr, 40);
                        }
                    }
                }
            } else {
                \Illuminate\Support\Facades\Cache::forget("evolution_qr_{$this->instance}");
                \Illuminate\Support\Facades\Cache::forget("evolution_qr_lock_{$this->instance}");
            }
        } catch (\Exception $e) {
            Log::warning("Evolution API connection check failed: " . $e->getMessage());
        }

        return $status;
    }

    /**
     * Extract phone number from WhatsApp JID (e.g., 6281234567890@s.whatsapp.net)
     */
    private function extractPhoneNumber(string $jid): string
    {
        return str_replace(['@s.whatsapp.net', '@g.us'], '', $jid);
    }

    /**
     * Normalize phone number format for sending.
     */
    private function normalizeNumber(string $number): string
    {
        // Remove any non-digit characters except leading +
        $number = preg_replace('/[^\d+]/', '', $number);

        // Remove leading + if present
        $number = ltrim($number, '+');

        // Ensure Indonesian numbers start with 62
        if (str_starts_with($number, '0')) {
            $number = '62' . substr($number, 1);
        }

        return $number;
    }
}
