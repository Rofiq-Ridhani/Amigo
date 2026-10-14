<?php

namespace App\Models;

use App\Support\Iso;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Percakapan 1-lawan-1 (kontrak §C.2). user_one_id selalu lebih kecil dari user_two_id.
 */
#[Fillable(['user_one_id', 'user_two_id', 'last_message_id', 'last_message_at'])]
class Conversation extends Model
{
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function userOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_one_id');
    }

    public function userTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_two_id');
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id')->withTrashed();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** Percakapan yang melibatkan user ini. */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $q) use ($userId) {
            $q->where('user_one_id', $userId)->orWhere('user_two_id', $userId);
        });
    }

    /** Ambil atau buat percakapan antara dua user (idempoten, aman dari race). */
    public static function between(int $a, int $b): self
    {
        [$one, $two] = $a < $b ? [$a, $b] : [$b, $a];

        return static::createOrFirst(['user_one_id' => $one, 'user_two_id' => $two]);
    }

    public function hasParticipant(int $userId): bool
    {
        return (int) $this->user_one_id === $userId || (int) $this->user_two_id === $userId;
    }

    public function peerIdFor(int $userId): int
    {
        return (int) $this->user_one_id === $userId ? (int) $this->user_two_id : (int) $this->user_one_id;
    }

    /**
     * Bentuk "Conversation" kontrak §D.1.
     *
     * @return array<string,mixed>
     */
    public function toApiArray(int $viewerId, int $unreadCount): array
    {
        $peer = (int) $this->user_one_id === $viewerId ? $this->userTwo : $this->userOne;

        return [
            'id' => $this->id,
            'peer' => $peer->toPublicArray(),
            'last_message' => $this->lastMessage?->toApiArray(),
            'unread_count' => $unreadCount,
            'last_message_at' => Iso::format($this->last_message_at),
            'created_at' => Iso::format($this->created_at),
        ];
    }
}
