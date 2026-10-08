<?php

namespace App\Services;

use App\Events\MessageSent;
use App\Models\Message;
use App\Models\User;

/**
 * Contract §5.2/5.3 — kirim, list, reply pesan.
 */
class MessageService
{
    /**
     * Kirim pesan (CONTRACT §5.2).
     */
    public function send(int $senderId, int $recipientId, ?string $body, ?int $replyToId = null, array $attachmentIds = []): array
    {
        abort_if($senderId === $recipientId, 403, 'Tidak bisa kirim ke diri sendiri');

        if ($replyToId) {
            $reply = Message::visible()->find($replyToId);
            abort_if(! $reply, 422, 'reply_to_id tidak valid');
            $pair = [$senderId, $recipientId];
            abort_if(
                ! in_array($reply->sender_id, $pair, true) || ! in_array($reply->recipient_id, $pair, true),
                422,
                'reply_to_id bukan dari conversation ini'
            );
        }

        if (empty(trim((string) $body)) && empty($attachmentIds)) {
            abort(422, 'body atau attachment wajib ada');
        }

        $message = Message::create([
            'sender_id'    => $senderId,
            'recipient_id' => $recipientId,
            'body'         => $body,
            'reply_to_id'  => $replyToId,
        ]);

        if (! empty($attachmentIds)) {
            \App\Models\MediaAttachment::whereIn('id', $attachmentIds)
                ->whereNull('message_id')
                ->update(['message_id' => $message->id]);
        }

        $message->load(['recipient', 'replyTo', 'reactions', 'attachments']);

        // Realtime broadcast (step be-socket akan subscribe channel ini)
        broadcast(new MessageSent($message))->toOthers();

        return $this->present($message, $senderId);
    }

    /**
     * List conversation dengan user lain (CONTRACT §5.3).
     */
    public function conversation(int $me, int $other, int $page = 1, int $perPage = 50, ?int $beforeId = null): array
    {
        $perPage = min(max($perPage, 1), 100);
        $page    = max($page, 1);

        $q = Message::visible()
            ->with(['reactions', 'attachments'])
            ->where(function ($w) use ($me, $other) {
                $w->where('sender_id', $me)->where('recipient_id', $other);
            })
            ->orWhere(function ($w) use ($me, $other) {
                $w->where('sender_id', $other)->where('recipient_id', $me);
            });

        if ($beforeId) {
            $q->where('id', '<', $beforeId);
        }

        $total    = (clone $q)->count();
        $messages = $q->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get()
            ->reverse()
            ->values();

        $userCache = User::whereIn('id', [$me, $other])->get(['id', 'name'])->keyBy('id');

        $data = $messages->map(fn ($m) => $this->present($m, $me, $userCache));

        return [
            'data'       => $data,
            'pagination' => [
                'current_page' => $page,
                'per_page'     => $perPage,
                'total'        => $total,
                'total_pages'  => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * Shape 1 pesan sesuai CONTRACT §7.2 (data envelope).
     */
    public function present(Message $m, ?int $viewerId = null, ?\Illuminate\Support\Collection $userCache = null): array
    {
        $senderName = $userCache?->get($m->sender_id)?->name
            ?? $m->sender?->name
            ?? User::find($m->sender_id)?->name;

        $replyPreview = null;
        if ($m->reply_to_id) {
            $reply = $m->replyTo ?? Message::find($m->reply_to_id);
            if ($reply) {
                $replyPreview = [
                    'id'          => $reply->id,
                    'body'        => $reply->is_deleted ? '[Pesan dihapus]' : $reply->body,
                    'sender_name' => $userCache?->get($reply->sender_id)?->name
                        ?? $reply->sender?->name
                        ?? User::find($reply->sender_id)?->name,
                ];
            }
        }

        $reactions = $m->relationLoaded('reactions') ? $m->reactions : $m->reactions()->get();
        $reactionList = [];
        foreach ($reactions->groupBy('emoji') as $emoji => $rows) {
            $reactionList[] = [
                'emoji' => $emoji,
                'count' => $rows->count(),
                'users' => $rows->pluck('user_id')->values()->all(),
            ];
        }

        $attachments = $m->relationLoaded('attachments') ? $m->attachments : $m->attachments()->get();
        $attachmentList = $attachments->map(fn ($a) => [
            'id'            => $a->id,
            'type'          => $a->type,
            'url'           => $a->file_path ? '/storage/' . ltrim($a->file_path, '/') : null,
            'thumbnail_url' => $a->thumbnail_path ? '/storage/' . ltrim($a->thumbnail_path, '/') : null,
            'file_name'     => $a->original_name,
            'file_size'     => $a->file_size,
            'mime_type'     => $a->mime_type,
        ])->values()->all();

        return [
            'id'               => $m->id,
            'sender_id'        => $m->sender_id,
            'sender_name'      => $senderName,
            'recipient_id'     => $m->recipient_id,
            'body'             => $m->is_deleted ? '[Pesan dihapus]' : $m->body,
            'reply_to_id'      => $m->reply_to_id,
            'reply_to_preview' => $replyPreview,
            'attachments'      => $attachmentList,
            'reactions'        => $reactionList,
            'is_edited'        => (bool) $m->is_edited,
            'is_deleted'       => (bool) $m->is_deleted,
            'delivered_at'     => optional($m->delivered_at)->toIso8601String(),
            'read_at'          => optional($m->read_at)->toIso8601String(),
            'edited_at'        => optional($m->edited_at)->toIso8601String(),
            'created_at'       => optional($m->created_at)->toIso8601String(),
            'updated_at'       => optional($m->updated_at)->toIso8601String(),
        ];
    }
}