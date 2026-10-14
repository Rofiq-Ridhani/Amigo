<?php

namespace App\Models;

use App\Support\Iso;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notifikasi Amigo (kontrak §C.6). Memakai tabel `notifications` sendiri; sengaja bernama
 * ChatNotification agar tidak tertukar dengan sistem notifikasi bawaan Laravel.
 */
#[Fillable(['user_id', 'type', 'title', 'body', 'data', 'read_at'])]
class ChatNotification extends Model
{
    use HasUuids;

    protected $table = 'notifications';

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Bentuk "Notification" kontrak §D.1.
     *
     * @return array<string,mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data,
            'read_at' => Iso::format($this->read_at),
            'created_at' => Iso::format($this->created_at),
        ];
    }
}
