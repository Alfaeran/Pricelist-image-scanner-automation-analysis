<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMessageLog extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'conversation_id',
        'direction',
        'message_type',
        'content',
        'media_url',
        'media_path',
        'wa_message_id',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * The conversation this message belongs to.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'conversation_id');
    }

    /**
     * Scope to incoming messages only.
     */
    public function scopeIncoming($query)
    {
        return $query->where('direction', 'incoming');
    }

    /**
     * Scope to outgoing messages only.
     */
    public function scopeOutgoing($query)
    {
        return $query->where('direction', 'outgoing');
    }

    /**
     * Log an incoming message.
     */
    public static function logIncoming(
        int $conversationId,
        string $type,
        ?string $content,
        ?string $mediaPath = null,
        ?string $waMessageId = null
    ): self {
        return static::create([
            'conversation_id' => $conversationId,
            'direction' => 'incoming',
            'message_type' => $type,
            'content' => $content,
            'media_path' => $mediaPath,
            'wa_message_id' => $waMessageId,
            'status' => 'received',
        ]);
    }

    /**
     * Log an outgoing message.
     */
    public static function logOutgoing(
        int $conversationId,
        string $content,
        string $status = 'sent'
    ): self {
        return static::create([
            'conversation_id' => $conversationId,
            'direction' => 'outgoing',
            'message_type' => 'text',
            'content' => $content,
            'status' => $status,
        ]);
    }
}
