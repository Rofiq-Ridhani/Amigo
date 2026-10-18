<?php

namespace App\Models;

use App\Support\Iso;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Percakapan (kontrak §C.2 dan §17).
 *  - direct: 1-lawan-1, user_one_id < user_two_id, tanpa baris anggota (perilaku lama).
 *  - group : anggota di tabel conversation_members; user_one_id/user_two_id kosong.
 */
#[Fillable(['type', 'name', 'created_by', 'user_one_id', 'user_two_id', 'last_message_id', 'last_message_at'])]
class Conversation extends Model
{
    public const TYPE_DIRECT = 'direct';

    public const TYPE_GROUP = 'group';

    public const MAX_MEMBERS = 50;

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

    /** Semua baris keanggotaan (termasuk yang sudah keluar). */
    public function members(): HasMany
    {
        return $this->hasMany(ConversationMember::class);
    }

    /** Anggota yang masih aktif, urut bergabung (pemilik lebih dulu). */
    public function activeMembers(): HasMany
    {
        return $this->hasMany(ConversationMember::class)
            ->whereNull('left_at')
            ->orderBy('joined_at')
            ->orderBy('id');
    }

    public function isGroup(): bool
    {
        return $this->type === self::TYPE_GROUP;
    }

    /**
     * Percakapan yang boleh diakses user ini:
     *  - direct: user adalah salah satu dari pasangan
     *  - group : user masih menjadi anggota aktif
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $q) use ($userId) {
            $q->where(function (Builder $direct) use ($userId) {
                $direct->where('type', self::TYPE_DIRECT)
                    ->where(function (Builder $pair) use ($userId) {
                        $pair->where('user_one_id', $userId)->orWhere('user_two_id', $userId);
                    });
            })->orWhere(function (Builder $group) use ($userId) {
                $group->where('type', self::TYPE_GROUP)
                    ->whereExists(function ($sub) use ($userId) {
                        $sub->select(DB::raw(1))
                            ->from('conversation_members')
                            ->whereColumn('conversation_members.conversation_id', 'conversations.id')
                            ->where('conversation_members.user_id', $userId)
                            ->whereNull('conversation_members.left_at');
                    });
            });
        });
    }

    /** Ambil atau buat percakapan DIRECT antara dua user (idempoten, aman dari race). */
    public static function between(int $a, int $b): self
    {
        [$one, $two] = $a < $b ? [$a, $b] : [$b, $a];

        return static::createOrFirst(['user_one_id' => $one, 'user_two_id' => $two], ['type' => self::TYPE_DIRECT]);
    }

    public function hasParticipant(int $userId): bool
    {
        if ($this->isGroup()) {
            return $this->members()->where('user_id', $userId)->whereNull('left_at')->exists();
        }

        return (int) $this->user_one_id === $userId || (int) $this->user_two_id === $userId;
    }

    /** Lawan bicara pada percakapan DIRECT. Tidak berlaku untuk grup. */
    public function peerIdFor(int $userId): int
    {
        if ($this->isGroup()) {
            throw new \LogicException('peerIdFor() hanya untuk percakapan direct.');
        }

        return (int) $this->user_one_id === $userId ? (int) $this->user_two_id : (int) $this->user_one_id;
    }

    /**
     * Id semua peserta aktif: pasangan untuk direct, anggota aktif untuk grup.
     *
     * @return array<int,int>
     */
    public function participantIds(): array
    {
        if (! $this->isGroup()) {
            return [(int) $this->user_one_id, (int) $this->user_two_id];
        }

        return $this->members()
            ->whereNull('left_at')
            ->orderBy('joined_at')->orderBy('id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Bentuk "Conversation" kontrak §D.1 / §17. `unread_count` dihitung pemanggil.
     *
     * @return array<string,mixed>
     */
    public function toApiArray(int $viewerId, int $unreadCount): array
    {
        if ($this->isGroup()) {
            $members = $this->activeMembers->loadMissing('user');

            return [
                'id' => $this->id,
                'type' => self::TYPE_GROUP,
                'name' => $this->name,
                'peer' => null,
                'owner_id' => $members->first(fn (ConversationMember $m) => $m->isOwner())?->user_id,
                'members_count' => $members->count(),
                'members' => $members->map(fn (ConversationMember $m) => [
                    'user' => $m->user->toPublicArray(),
                    'role' => $m->role,
                ])->values()->all(),
                'last_message' => $this->lastMessage?->toApiArray(),
                'unread_count' => $unreadCount,
                // Grup tanpa pesan memakai waktu dibuat agar tetap bisa diurutkan
                'last_message_at' => Iso::format($this->last_message_at ?? $this->created_at),
                'created_at' => Iso::format($this->created_at),
            ];
        }

        $peer = (int) $this->user_one_id === $viewerId ? $this->userTwo : $this->userOne;

        return [
            'id' => $this->id,
            'type' => self::TYPE_DIRECT,
            'name' => null,
            'peer' => $peer->toPublicArray(),
            'last_message' => $this->lastMessage?->toApiArray(),
            'unread_count' => $unreadCount,
            'last_message_at' => Iso::format($this->last_message_at),
            'created_at' => Iso::format($this->created_at),
        ];
    }
}
