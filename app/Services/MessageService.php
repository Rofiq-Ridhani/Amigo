<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Realtime\RealtimePublisher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Logika bisnis pengiriman pesan (kontrak §D.5). Dipakai controller dan command artisan.
 */
class MessageService
{
    public function __construct(private readonly RealtimePublisher $realtime) {}

    /**
     * Simpan pesan lalu publish `message:new`. Idempoten berdasarkan client_uuid.
     *
     * @return array{0:Message,1:bool} [pesan, apakah baru dibuat]
     */
    public function send(Conversation $conversation, User $sender, string $clientUuid, string $body, ?int $replyToId = null): array
    {
        $existing = $this->findExisting($sender, $clientUuid, $conversation);

        if ($existing !== null) {
            return [$existing, false];
        }

        $recipientId = $conversation->peerIdFor($sender->id);

        try {
            $message = DB::transaction(function () use ($conversation, $sender, $recipientId, $clientUuid, $body, $replyToId) {
                $message = Message::create([
                    'conversation_id' => $conversation->id,
                    'sender_id' => $sender->id,
                    'recipient_id' => $recipientId,
                    'client_uuid' => $clientUuid,
                    'reply_to_id' => $replyToId,
                    'body' => $body,
                    'revision' => 1,
                ]);

                $conversation->forceFill([
                    'last_message_id' => $message->id,
                    'last_message_at' => $message->created_at,
                ])->save();

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            // Dua request dengan client_uuid sama berlomba; yang kalah mengambil milik pemenang.
            return [$this->findExisting($sender, $clientUuid, $conversation, true), false];
        }

        $message->load('replyTo');

        // Setelah commit (kontrak §5 alur pesan, langkah 4)
        $this->realtime->publish(
            'messages',
            'message:new',
            [$sender->id, $recipientId],
            ['message' => $message->toApiArray()],
            $recipientId,
        );

        return [$message, true];
    }

    private function findExisting(User $sender, string $clientUuid, Conversation $conversation, bool $required = false): ?Message
    {
        $query = Message::withTrashed()
            ->where('sender_id', $sender->id)
            ->where('client_uuid', $clientUuid);

        $existing = $required ? $query->firstOrFail() : $query->first();

        if ($existing === null) {
            return null;
        }

        if ((int) $existing->conversation_id !== (int) $conversation->id) {
            throw ValidationException::withMessages([
                'client_uuid' => 'client_uuid sudah dipakai pada percakapan lain.',
            ]);
        }

        return $existing->loadMissing('replyTo');
    }
}
