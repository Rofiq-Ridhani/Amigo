<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaAttachment extends Model
{
    protected $fillable = ['message_id', 'type', 'file_path', 'thumbnail_path', 'original_name', 'mime_type', 'file_size'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
