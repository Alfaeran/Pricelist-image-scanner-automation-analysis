<?php

namespace App\Services\WhatsApp;

/**
 * Normalized WhatsApp Message DTO
 *
 * Provides a unified structure regardless of whether the message
 * came from Meta Cloud API or Evolution API.
 */
class WhatsAppMessage
{
    public function __construct(
        /** Sender's phone number (with country code, e.g., 6281234567890) */
        public readonly string $from,

        /** Message text content (empty for media-only messages) */
        public readonly string $text = '',

        /** Type of message: 'text', 'image', 'document', 'audio', 'video', 'location', 'contacts' */
        public readonly string $type = 'text',

        /** Media ID from WhatsApp (for downloading) */
        public readonly ?string $mediaId = null,

        /** Direct URL to media (if available) */
        public readonly ?string $mediaUrl = null,

        /** MIME type of media (e.g., 'image/jpeg') */
        public readonly ?string $mediaMimeType = null,

        /** Original filename for documents */
        public readonly ?string $mediaFilename = null,

        /** Image/document caption */
        public readonly ?string $caption = null,

        /** WhatsApp message ID (for tracking) */
        public readonly ?string $messageId = null,

        /** Sender's push name (display name) */
        public readonly ?string $senderName = null,

        /** Unix timestamp of the message */
        public readonly ?int $timestamp = null,

        /** Raw payload from the API (for debugging) */
        public readonly array $rawPayload = [],
    ) {}

    /**
     * Check if this message contains media (image, document, etc.)
     */
    public function hasMedia(): bool
    {
        return in_array($this->type, ['image', 'document', 'audio', 'video'])
            && ($this->mediaId || $this->mediaUrl);
    }

    /**
     * Check if this message is an image.
     */
    public function isImage(): bool
    {
        return $this->type === 'image';
    }

    /**
     * Check if this message is a document.
     */
    public function isDocument(): bool
    {
        return $this->type === 'document';
    }

    /**
     * Get the effective text content (caption for media, text for text messages).
     */
    public function getEffectiveText(): string
    {
        return $this->caption ?: $this->text;
    }

    /**
     * Convert to array for serialization.
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'text' => $this->text,
            'type' => $this->type,
            'media_id' => $this->mediaId,
            'media_url' => $this->mediaUrl,
            'media_mime_type' => $this->mediaMimeType,
            'media_filename' => $this->mediaFilename,
            'caption' => $this->caption,
            'message_id' => $this->messageId,
            'sender_name' => $this->senderName,
            'timestamp' => $this->timestamp,
        ];
    }
}
