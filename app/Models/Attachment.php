<?php

namespace App\Models;

use App\Support\Iso;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Metadata lampiran (kontrak §C.4). File disimpan di disk privat; akses hanya lewat controller. */
#[Fillable([
    'message_id', 'uploader_id', 'disk', 'path', 'original_name',
    'mime_type', 'kind', 'size_bytes', 'width', 'height',
])]
class Attachment extends Model
{
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class)->withTrashed();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploader_id');
    }

    /**
     * Bentuk "Attachment" kontrak §D.1.
     *
     * @return array<string,mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'message_id' => $this->message_id,
            'kind' => $this->kind,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => (int) $this->size_bytes,
            'width' => $this->width,
            'height' => $this->height,
            'url' => "/api/v1/attachments/{$this->id}/content",
            'download_url' => "/api/v1/attachments/{$this->id}/content?download=1",
            'created_at' => Iso::format($this->created_at),
        ];
    }
}
