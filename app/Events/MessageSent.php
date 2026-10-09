<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Contract §6.4 — broadcast message:new to recipient (Redis → Socket.IO).
 */
class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message)
    {
        $this->message->load('sender', 'replyTo', 'reactions', 'media');
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.' . $this->message->recipient_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message:new';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'sender_id' => $this->message->sender_id,
            'sender' => [
                'id' => $this->message->sender->id,
                'name' => $this->message->sender->name,
                'avatar' => $this->message->sender->avatar,
            ],
            'recipient_id' => $this->message->recipient_id,
            'body' => $this->message->body,
            'reply_to_id' => $this->message->reply_to_id,
            'reply_to_preview' => $this->message->replyTo ? [
                'id' => $this->message->replyTo->id,
                'body' => $this->message->replyTo->body,
                'sender_name' => $this->message->replyTo->sender?->name,
            ] : null,
            'attachments' => $this->message->media->map(fn ($m) => [
                'id' => $m->id,
                'type' => $m->type,
                'url' => "/storage/media/{$m->type}/" . basename($m->file_path),
                'thumbnail_url' => $m->thumbnail_path ? "/storage/media/thumbs/" . basename($m->thumbnail_path) : null,
                'file_name' => $m->original_name,
                'file_size' => $m->file_size,
                'mime_type' => $m->mime_type,
            ])->toArray(),
            'reactions' => $this->message->reactions->groupBy('emoji')->map(fn ($group) => [
                'emoji' => $group->first()->emoji,
                'count' => $group->count(),
                'users' => $group->pluck('user_id')->toArray(),
            ])->values()->toArray(),
            'is_edited' => $this->message->is_edited,
            'is_deleted' => $this->message->is_deleted,
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
