<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use HasFactory;

    /** CONTRACT §7.2 — fillable (renamed) */
    protected $fillable = [
        'sender_id',
        'recipient_id',
        'body',
        'reply_to_id',
        'is_edited',
        'is_deleted',
        'deleted_at',
        'delivered_at',
        'edited_at',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at'      => 'datetime',
            'delivered_at' => 'datetime',
            'deleted_at'    => 'datetime',
            'edited_at'    => 'datetime',
            'is_edited'    => 'boolean',
            'is_deleted'   => 'boolean',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /** Boilerplate compatibility — kept so existing views don't 500 */
    public function receiver(): BelongsTo
    {
        return $this->recipient();
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MediaAttachment::class);
    }

    /**
     * Conversation between two users (any direction).
     */
    public static function getConversation(int $userId1, int $userId2): \Illuminate\Database\Eloquent\Collection
    {
        return self::where(function ($query) use ($userId1, $userId2) {
            $query->where('sender_id', $userId1)->where('recipient_id', $userId2);
        })->orWhere(function ($query) use ($userId1, $userId2) {
            $query->where('sender_id', $userId2)->where('recipient_id', $userId1);
        })
            ->where('is_deleted', false)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Scope: exclude soft-deleted (contract — hidden from default list).
     */
    public function scopeVisible(Builder $q): Builder
    {
        return $q->where('is_deleted', false);
    }
}
