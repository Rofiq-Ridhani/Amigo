<?php

namespace App\Models;

use App\Support\Iso;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
            'attachments' => [],   // Fase 6
            'reactions' => [],     // Fase 5
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
            'has_attachments' => false,
            'deleted' => $deleted,
        ];
    }
}
