<?php

namespace App\Services;

use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use App\Realtime\RealtimePublisher;
use App\Support\Iso;
use Illuminate\Support\Facades\DB;

/**
 * Edit, hapus, dan reaksi pesan (kontrak §D.5). Validasi hak akses dilakukan di controller;
 * kelas ini hanya mengubah data lalu memublikasikan event SETELAH data tersimpan.
 * Setiap perubahan menaikkan `revision` agar client bisa membuang data usang (kontrak §H.7).
 */
class MessageActionService
{
    public function __construct(
        private readonly RealtimePublisher $realtime,
        private readonly UnreadService $unread,
    ) {}

    /** @return array{0:Message,1:bool} [pesan terbaru, apakah ada perubahan] */
    public function edit(Message $message, string $body): array
    {
        if ($body === (string) $message->body) {
            return [$message, false];
        }

        $now = now();

        Message::whereKey($message->id)->update([
            'body' => $body,
            'edited_at' => $now,
            'updated_at' => $now,
            'revision' => DB::raw('revision + 1'),
        ]);

        $fresh = $this->fresh($message->id);

        $this->realtime->publish('messages', 'message:updated', $this->participants($fresh), [
            'message' => $fresh->toApiArray(),
        ]);

        return [$fresh, true];
    }

    /** Soft delete untuk semua peserta. Idempoten: sudah terhapus -> tidak ada event. */
    public function delete(Message $message): Message
    {
        if ($message->trashed()) {
            return $message;
        }

        $now = now();

        DB::transaction(function () use ($message, $now) {
            MessageReaction::where('message_id', $message->id)->delete();

            Message::whereKey($message->id)->update([
                'deleted_at' => $now,
                'updated_at' => $now,
                'revision' => DB::raw('revision + 1'),
            ]);
        });

        $fresh = $this->fresh($message->id);

        $this->realtime->publish('messages', 'message:deleted', $this->participants($fresh), [
            'conversation_id' => $fresh->conversation_id,
            'message_id' => $fresh->id,
            'revision' => $fresh->revision,
            'deleted_at' => Iso::format($fresh->deleted_at),
        ]);

        // Pesan terhapus tidak lagi dihitung sebagai unread (kontrak §C.8)
        $this->realtime->publish('messages', 'unread:update', [$fresh->recipient_id], [
            'conversation_id' => $fresh->conversation_id,
            'unread_count' => $this->unread->forConversation($fresh->recipient_id, $fresh->conversation_id),
            'total_unread' => $this->unread->total($fresh->recipient_id),
        ]);

        return $fresh;
    }

    /** Pasang/ganti reaksi saya (satu per user per pesan). @return array{0:Message,1:bool} */
    public function react(Message $message, User $user, string $emoji): array
    {
        $existing = MessageReaction::where('message_id', $message->id)->where('user_id', $user->id)->first();

        if ($existing !== null && $existing->emoji === $emoji) {
            return [$this->fresh($message->id), false];
        }

        DB::transaction(function () use ($message, $user, $emoji) {
            MessageReaction::updateOrCreate(
                ['message_id' => $message->id, 'user_id' => $user->id],
                ['emoji' => $emoji],
            );
            $this->bump($message->id);
        });

        return [$this->publishReaction($message->id), true];
    }

    /** Hapus reaksi saya. Idempoten. @return array{0:Message,1:bool} */
    public function unreact(Message $message, User $user): array
    {
        $removed = DB::transaction(function () use ($message, $user) {
            $count = MessageReaction::where('message_id', $message->id)->where('user_id', $user->id)->delete();

            if ($count > 0) {
                $this->bump($message->id);
            }

            return $count > 0;
        });

        return $removed ? [$this->publishReaction($message->id), true] : [$this->fresh($message->id), false];
    }

    private function bump(int $messageId): void
    {
        $now = now();

        Message::whereKey($messageId)->update([
            'updated_at' => $now,
            'revision' => DB::raw('revision + 1'),
        ]);
    }

    private function publishReaction(int $messageId): Message
    {
        $fresh = $this->fresh($messageId);

        $this->realtime->publish('messages', 'message:reaction', $this->participants($fresh), [
            'conversation_id' => $fresh->conversation_id,
            'message_id' => $fresh->id,
            'revision' => $fresh->revision,
            'reactions' => $fresh->reactionsForApi(),
        ]);

        return $fresh;
    }

    private function fresh(int $messageId): Message
    {
        return Message::withTrashed()->with(['replyTo', 'reactions'])->findOrFail($messageId);
    }

    /** @return array<int,int> */
    private function participants(Message $message): array
    {
        return [(int) $message->sender_id, (int) $message->recipient_id];
    }
}
