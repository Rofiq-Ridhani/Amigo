<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Event realtime yang menunggu/sudah dipublish ke Redis (kontrak §J). */
#[Fillable(['event_id', 'channel', 'payload', 'attempts', 'published_at', 'created_at'])]
class OutboxEvent extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
