<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Meta WhatsApp Cloud API Service (Official)
 *
 * Uses the official Meta Cloud API for sending/receiving WhatsApp messages.
 * Requires: Meta Business Account, Developer App, verified phone number.
 *
 * Free tier: 1,000 service conversations/month.
 *
 * Config keys:
 * - WHATSAPP_META_TOKEN: Permanent system user token
 * - WHATSAPP_META_PHONE_ID: Phone number ID from Meta dashboard
 * - WHATSAPP_META_VERIFY_TOKEN: Custom token for webhook verification
 * - WHATSAPP_META_APP_SECRET: App secret for HMAC verification
 */
class MetaCloudApiService implements WhatsAppServiceInterface
{
    private string $baseUrl = 'https://graph.facebook.com/v21.0';
    private string $token;
    private string $phoneNumberId;
    private string $verifyToken;
    private string $appSecret;

    public function __construct()
    {
        $this->token = config('services.whatsapp.meta.token', '');
        $this->phoneNumberId = config('services.whatsapp.meta.phone_number_id', '');
        $this->verifyToken = config('services.whatsapp.meta.verify_token', '');
        $this->appSecret = config('services.whatsapp.meta.app_secret', '');
    }

    /**
     * @inheritDoc
     */
    public function sendTextMessage(string $to, string $message): bool
    {
        try {
            $response = Http::withToken($this->token)
                ->post("{$this->baseUrl}/{$this->phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body' => $message,
                    ],
                ]);

            if ($response->successful()) {
                Log::info("WhatsApp text sent to {$to}");
                return true;
            }

            Log::error("WhatsApp send failed: " . $response->body());
            return false;
        } catch (\Exception $e) {
            Log::error("WhatsApp send error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function sendImageMessage(string $to, string $imageUrl, string $caption = ''): bool
    {
        try {
            $response = Http::withToken($this->token)
                ->post("{$this->baseUrl}/{$this->phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                    'type' => 'image',
                    'image' => [
                        'link' => $imageUrl,
                        'caption' => $caption,
                    ],
                ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error("WhatsApp image send error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function sendDocument(string $to, string $documentUrl, string $filename, string $caption = ''): bool
    {
        try {
            $response = Http::withToken($this->token)
                ->post("{$this->baseUrl}/{$this->phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                    'type' => 'document',
                    'document' => [
                        'link' => $documentUrl,
                        'filename' => $filename,
                        'caption' => $caption,
                    ],
                ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error("WhatsApp document send error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function downloadMedia(string $mediaId): string
    {
        // Step 1: Get media URL from Meta
        $response = Http::withToken($this->token)
            ->get("{$this->baseUrl}/{$mediaId}");

        if (!$response->successful()) {
            throw new \RuntimeException("Failed to get media URL: " . $response->body());
        }

        $mediaUrl = $response->json('url');

        // Step 2: Download the actual file
        $fileResponse = Http::withToken($this->token)
            ->get($mediaUrl);

        if (!$fileResponse->successful()) {
            throw new \RuntimeException("Failed to download media file");
        }

        // Step 3: Save to local storage
        $mimeType = $response->json('mime_type', 'image/jpeg');
        $extension = match (true) {
            str_contains($mimeType, 'jpeg'), str_contains($mimeType, 'jpg') => 'jpg',
            str_contains($mimeType, 'png') => 'png',
            str_contains($mimeType, 'webp') => 'webp',
            str_contains($mimeType, 'pdf') => 'pdf',
            str_contains($mimeType, 'zip') => 'zip',
            default => 'bin',
        };

        $filename = 'whatsapp/' . date('Y-m-d') . '/' . uniqid('wa_') . '.' . $extension;
        Storage::disk('public')->put($filename, $fileResponse->body());

        return $filename;
    }

    /**
     * @inheritDoc
     */
    public function verifyWebhook(Request $request): mixed
    {
        $mode = $request->query('hub_mode') ?? $request->query('hub.mode') ?? $request->input('hub_mode');
        $token = $request->query('hub_verify_token') ?? $request->query('hub.verify_token') ?? $request->input('hub_verify_token');
        $challenge = $request->query('hub_challenge') ?? $request->query('hub.challenge') ?? $request->input('hub_challenge');

        if ($mode === 'subscribe' && $token === $this->verifyToken) {
            Log::info("WhatsApp webhook verified successfully");
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::warning("WhatsApp webhook verification failed", [
            'mode' => $mode,
            'token_received' => $token,
            'expected_token' => $this->verifyToken,
        ]);
        return response('Forbidden', 403);
    }

    /**
     * @inheritDoc
     */
    public function parseIncomingMessage(Request $request): ?WhatsAppMessage
    {
        // Verify HMAC signature for security
        if ($this->appSecret && !$this->verifySignature($request)) {
            Log::warning("WhatsApp webhook signature verification failed");
            return null;
        }

        $payload = $request->all();

        // Navigate Meta's nested webhook structure
        $entry = $payload['entry'][0] ?? null;
        if (!$entry) return null;

        $changes = $entry['changes'][0] ?? null;
        if (!$changes) return null;

        $value = $changes['value'] ?? null;
        if (!$value || ($value['messaging_product'] ?? '') !== 'whatsapp') return null;

        // Check for status updates (not messages)
        if (isset($value['statuses'])) return null;

        $messages = $value['messages'] ?? [];
        if (empty($messages)) return null;

        $msg = $messages[0];
        $contacts = $value['contacts'][0] ?? [];

        $type = $msg['type'] ?? 'text';

        return new WhatsAppMessage(
            from: $msg['from'] ?? '',
            text: $msg['text']['body'] ?? '',
            type: $type,
            mediaId: $msg[$type]['id'] ?? null,
            mediaUrl: null, // Meta doesn't provide direct URLs in webhooks
            mediaMimeType: $msg[$type]['mime_type'] ?? null,
            mediaFilename: $msg[$type]['filename'] ?? null,
            caption: $msg[$type]['caption'] ?? null,
            messageId: $msg['id'] ?? null,
            senderName: $contacts['profile']['name'] ?? null,
            timestamp: isset($msg['timestamp']) ? (int) $msg['timestamp'] : null,
            rawPayload: $payload,
        );
    }

    /**
     * @inheritDoc
     */
    public function isConnected(): bool
    {
        if (empty($this->token) || empty($this->phoneNumberId)) {
            return false;
        }

        try {
            $response = Http::withToken($this->token)
                ->get("{$this->baseUrl}/{$this->phoneNumberId}");
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getConnectionStatus(): array
    {
        return [
            'driver' => 'meta_cloud',
            'connected' => $this->isConnected(),
            'phone_number_id' => $this->phoneNumberId,
            'configured' => !empty($this->token) && !empty($this->phoneNumberId),
        ];
    }

    /**
     * Verify the X-Hub-Signature-256 header from Meta.
     */
    private function verifySignature(Request $request): bool
    {
        $signature = $request->header('X-Hub-Signature-256');
        if (!$signature) return false;

        $expectedSignature = 'sha256=' . hash_hmac('sha256', $request->getContent(), $this->appSecret);

        return hash_equals($expectedSignature, $signature);
    }
}
