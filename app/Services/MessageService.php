<?php

namespace App\Services;

use App\Models\Attachment;
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
    public function __construct(
        private readonly RealtimePublisher $realtime,
        private readonly UnreadService $unread,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Simpan pesan + notifikasi (satu transaksi), lalu publish `message:new`, `unread:update`,
     * `notification:new`. Idempoten berdasarkan client_uuid.
     *
     * @return array{0:Message,1:bool} [pesan, apakah baru dibuat]
     */
    public function send(
        Conversation $conversation,
        User $sender,
        string $clientUuid,
        ?string $body,
        ?int $replyToId = null,
        array $attachmentIds = [],
    ): array {
        $existing = $this->findExisting($sender, $clientUuid, $conversation);

        if ($existing !== null) {
            return [$existing, false];
        }

        $recipientId = $conversation->peerIdFor($sender->id);
        $attachmentIds = array_values(array_unique(array_map('intval', $attachmentIds)));

        // Diperiksa SETELAH pengecekan idempotensi: kirim ulang dengan client_uuid yang sama
        // (lampiran sudah terpakai oleh pesan itu) tetap harus berhasil.
        $this->assertAttachmentsUsable($sender, $attachmentIds);

        try {
            [$message, $notification] = DB::transaction(function () use ($conversation, $sender, $recipientId, $clientUuid, $body, $replyToId, $attachmentIds) {
                $message = Message::create([
                    'conversation_id' => $conversation->id,
                    'sender_id' => $sender->id,
                    'recipient_id' => $recipientId,
                    'client_uuid' => $clientUuid,
                    'reply_to_id' => $replyToId,
                    'body' => $body,
                    'revision' => 1,
                ]);

                if ($attachmentIds !== []) {
                    $attached = Attachment::whereIn('id', $attachmentIds)
                        ->where('uploader_id', $sender->id)
                        ->whereNull('message_id')
                        ->update(['message_id' => $message->id]);

                    // Lampiran dipakai pesan lain di saat bersamaan -> batalkan seluruh transaksi
                    if ($attached !== count($attachmentIds)) {
                        throw $this->attachmentsInvalid();
                    }
                }

                $conversation->forceFill([
                    'last_message_id' => $message->id,
                    'last_message_at' => $message->created_at,
                ])->save();

                // Notifikasi disimpan SEBELUM event dipublish (kontrak §12 & materi)
                $notification = $this->notifications->createForMessage($message, $sender->name);

                return [$message, $notification];
            });
        } catch (UniqueConstraintViolationException) {
            // Dua request dengan client_uuid sama berlomba; yang kalah mengambil milik pemenang.
            return [$this->findExisting($sender, $clientUuid, $conversation, true), false];
        }

        $message->load(['replyTo.attachments', 'attachments']);

        // Setelah commit (kontrak §5 alur pesan, langkah 4)
        $this->realtime->publish(
            'messages',
            'message:new',
            [$sender->id, $recipientId],
            ['message' => $message->toApiArray()],
            $recipientId,
        );

        // Badge unread penerima (kontrak §H.4): angka absolut, client mengganti nilainya
        $this->realtime->publish('messages', 'unread:update', [$recipientId], [
            'conversation_id' => $conversation->id,
            'unread_count' => $this->unread->forConversation($recipientId, $conversation->id),
            'total_unread' => $this->unread->total($recipientId),
        ]);

        // Toast + badge notifikasi
        $this->notifications->publishNew($notification);

        return [$message, true];
    }

    /** @param  array<int,int>  $attachmentIds */
    private function assertAttachmentsUsable(User $sender, array $attachmentIds): void
    {
        if ($attachmentIds === []) {
            return;
        }

        $usable = Attachment::whereIn('id', $attachmentIds)
            ->where('uploader_id', $sender->id)
            ->whereNull('message_id')
            ->count();

        if ($usable !== count($attachmentIds)) {
            throw $this->attachmentsInvalid();
        }
    }

    private function attachmentsInvalid(): ValidationException
    {
        return ValidationException::withMessages([
            'attachment_ids' => 'Lampiran tidak valid, bukan milik Anda, atau sudah dipakai pesan lain.',
        ]);
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

        return $existing->loadMissing(['replyTo.attachments', 'attachments']);
    }
}
