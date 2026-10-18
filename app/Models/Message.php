<?php

namespace App\Models;

use App\Support\Iso;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pesan (kontrak §C.3). Dihapus = soft delete -> tampil sebagai tombstone.
 */
#[Fillable([
    'conversation_id', 'sender_id', 'recipient_id', 'client_uuid', 'reply_to_id',
    'body', 'revision', 'delivered_at', 'read_at', 'edited_at',
])]
class Message extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'edited_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id')->withTrashed();
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(MessageMention::class)->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class)->orderBy('id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class)->orderBy('id');
    }

    /**
     * Daftar reaksi dalam bentuk kontrak §D.1: [{user_id, emoji}].
     *
     * @return array<int,array{user_id:int,emoji:string}>
     */
    public function reactionsForApi(): array
    {
        if ($this->trashed()) {
            return [];
        }

        return $this->reactions
            ->map(fn (MessageReaction $r) => ['user_id' => (int) $r->user_id, 'emoji' => $r->emoji])
            ->values()
            ->all();
    }

    /**
     * Id user yang di-@mention (hanya pesan grup). Pesan direct tidak punya mention.
     *
     * @return array<int,int>
     */
    public function mentionIds(): array
    {
        if ($this->recipient_id !== null) {
            return [];
        }

        return $this->mentions->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * Jumlah anggota (selain pengirim) yang sudah membaca pesan grup ini, dari kursor baca mereka.
     * null untuk pesan direct (status dibaca direct memakai read_at). Pemanggil sebaiknya sudah
     * memuat relasi `conversation.activeMembers` agar tidak ada query per pesan.
     */
    public function readByCount(): ?int
    {
        if ($this->recipient_id !== null) {
            return null; // pesan direct
        }

        return $this->conversation->activeMembers
            ->filter(fn (ConversationMember $m) => (int) $m->user_id !== (int) $this->sender_id
                && (int) $m->last_read_message_id >= (int) $this->id)
            ->count();
    }

    /** sent -> delivered -> read (kontrak §H.2). Dihitung server, bukan client. */
    public function status(): string
    {
        if ($this->read_at !== null) {
            return 'read';
        }

        return $this->delivered_at !== null ? 'delivered' : 'sent';
    }

    /**
     * Bentuk "Message" kontrak §D.1. Pesan terhapus menjadi tombstone.
     *
     * @return array<string,mixed>
     */
    public function toApiArray(): array
    {
        $deleted = $this->trashed();

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'client_uuid' => $this->client_uuid,
            'sender_id' => $this->sender_id,
            'recipient_id' => $this->recipient_id,
            'body' => $deleted ? null : $this->body,
            'reply_to' => $deleted ? null : $this->replyPreview(),
            'attachments' => $deleted ? [] : $this->attachments->map(fn (Attachment $a) => $a->toApiArray())->values()->all(),
            'reactions' => $this->reactionsForApi(),
            'mentions' => $deleted ? [] : $this->mentionIds(),
            'read_by_count' => $deleted ? null : $this->readByCount(),
            'status' => $this->status(),
            'delivered_at' => Iso::format($this->delivered_at),
            'read_at' => Iso::format($this->read_at),
            'edited_at' => Iso::format($this->edited_at),
            'deleted_at' => Iso::format($this->deleted_at),
            'revision' => $this->revision,
            'created_at' => Iso::format($this->created_at),
        ];
    }

    /** @return array<string,mixed>|null */
    private function replyPreview(): ?array
    {
        if ($this->reply_to_id === null) {
            return null;
        }

        $original = $this->replyTo;

        if ($original === null) {
            return null;
        }

        $deleted = $original->trashed();

        return [
            'id' => $original->id,
            'sender_id' => $original->sender_id,
            'body_preview' => $deleted ? null : mb_substr((string) $original->body, 0, 100),
            'has_attachments' => ! $deleted && $original->attachments->isNotEmpty(),
            'deleted' => $deleted,
        ];
    }
}
