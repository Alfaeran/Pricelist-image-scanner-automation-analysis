<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppConversation extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_conversations';

    protected $fillable = [
        'phone_number',
        'sender_name',
        'user_id',
        'pricelist_id',
        'last_message_at',
        'context',
        'status',
    ];

    protected $casts = [
        'context' => 'array',
        'last_message_at' => 'datetime',
    ];

    /**
     * Associated Laravel user (optional).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Last associated pricelist being processed.
     */
    public function pricelist(): BelongsTo
    {
        return $this->belongsTo(Pricelist::class);
    }

    /**
     * All messages in this conversation.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessageLog::class, 'conversation_id');
    }

    /**
     * Get or create a conversation for a phone number.
     */
    public static function findOrCreateByPhone(string $phone, ?string $name = null): self
    {
        return static::updateOrCreate(
            ['phone_number' => $phone],
            array_filter([
                'sender_name' => $name,
                'last_message_at' => now(),
                'status' => 'active',
            ])
        );
    }
}
