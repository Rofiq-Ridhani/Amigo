<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\ChatNotification;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageMention;
use App\Models\User;
use App\Realtime\RealtimePublisher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Logika bisnis pengiriman pesan (kontrak §D.5, §17). Dipakai controller dan command artisan.
 *
 * direct: 1 penerima, notifikasi per pesan, status sent/delivered/read (perilaku lama).
 * grup  : tanpa penerima tunggal; event ke SELURUH anggota aktif; notifikasi HANYA untuk yang di-@mention.
 */
class MessageService
{
    public function __construct(
        private readonly RealtimePublisher $realtime,
        private readonly UnreadService $unread,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Simpan pesan (+ notifikasi) dalam satu transaksi, lalu publish event. Idempoten berdasarkan client_uuid.
     *
     * @param  array<int,int>  $attachmentIds
     * @param  array<int,int>  $mentionIds  hanya untuk grup
     * @param  int|null  $threadId  balasan dalam thread (Fase 9, hanya grup)
     * @return array{0:Message,1:bool} [pesan, apakah baru dibuat]
     */
    public function send(
        Conversation $conversation,
        User $sender,
        string $clientUuid,
        ?string $body,
        ?int $replyToId = null,
        array $attachmentIds = [],
        array $mentionIds = [],
        ?int $threadId = null,
    ): array {
        $existing = $this->findExisting($sender, $clientUuid, $conversation);

        if ($existing !== null) {
            return [$existing, false];
        }

        $isGroup = $conversation->isGroup();
        $recipientId = $isGroup ? null : $conversation->peerIdFor($sender->id);
        $attachmentIds = array_values(array_unique(array_map('intval', $attachmentIds)));
        $mentionIds = array_values(array_unique(array_map('intval', $mentionIds)));

        // Diperiksa SETELAH pengecekan idempotensi: kirim ulang dengan client_uuid yang sama
        // (lampiran sudah terpakai oleh pesan itu) tetap harus berhasil.
        $this->assertAttachmentsUsable($sender, $attachmentIds);
        $this->assertGroupRules($conversation, $sender, $replyToId, $mentionIds, $threadId);

        try {
            [$message, $notifications] = DB::transaction(function () use ($conversation, $sender, $recipientId, $clientUuid, $body, $replyToId, $attachmentIds, $mentionIds, $isGroup, $threadId) {
                $message = Message::create([
                    'conversation_id' => $conversation->id,
                    'sender_id' => $sender->id,
                    'recipient_id' => $recipientId,
                    'client_uuid' => $clientUuid,
                    'reply_to_id' => $replyToId,
                    'thread_id' => $threadId,
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
                $notifications = [];

                if ($isGroup) {
                    foreach ($mentionIds as $userId) {
                        MessageMention::create(['message_id' => $message->id, 'user_id' => $userId]);
                        $notifications[] = $this->notifications->createForMention($message, $sender->name, (string) $conversation->name, $userId);
                    }
                } else {
                    $notifications[] = $this->notifications->createForMessage($message, $sender->name);
                }

                return [$message, $notifications];
            });
        } catch (UniqueConstraintViolationException) {
            // Dua request dengan client_uuid sama berlomba; yang kalah mengambil milik pemenang.
            return [$this->findExisting($sender, $clientUuid, $conversation, true), false];
        }

        $message->load(['replyTo.attachments', 'attachments', 'mentions']);

        if ($isGroup) {
            $conversation->load('activeMembers');
            $message->setRelation('conversation', $conversation);
        }

        $this->publishNew($conversation, $sender, $message, $recipientId);

        foreach ($notifications as $notification) {
            $this->notifications->publishNew($notification);
        }

        return [$message, true];
    }

    /** `message:new` + `unread:update` (kontrak §E.3, §H.4). Atau thread:message:new untuk thread (kontrak §18). Panggil SETELAH commit. */
    private function publishNew(Conversation $conversation, User $sender, Message $message, ?int $recipientId): void
    {
        // Thread: notifikasi HANYA ke peserta thread (penulis asal + yang pernah balas)
        if ($message->thread_id !== null) {
            $this->publishThreadNew($conversation, $sender, $message);

            return;
        }

        if ($recipientId !== null) {
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

            return;
        }

        // Grup: semua anggota aktif menerima pesan; tanpa ACK per anggota (tidak ada status "delivered" grup)
        $memberIds = $conversation->participantIds();

        $this->realtime->publish('messages', 'message:new', $memberIds, ['message' => $message->toApiArray()]);

        foreach ($memberIds as $memberId) {
            if ($memberId === $sender->id) {
                continue;
            }

            $this->realtime->publish('messages', 'unread:update', [$memberId], [
                'conversation_id' => $conversation->id,
                'unread_count' => $this->unread->forConversation($memberId, $conversation->id),
                'total_unread' => $this->unread->total($memberId),
            ]);
        }
    }

    /** Thread: notifikasi ke penulis asal thread + peserta thread lainnya (kontrak §18). */
    private function publishThreadNew(Conversation $conversation, User $sender, Message $message): void
    {
        // Peserta thread = penulis asal thread + semua yang sudah balas di thread itu
        $threadRoot = Message::find($message->thread_id);
        $participantIds = [$threadRoot->sender_id];

        Message::where('thread_id', $message->thread_id)
            ->where('id', '!=', $message->id)
            ->distinct()
            ->pluck('sender_id')
            ->each(function ($id) use (&$participantIds) {
                if (! in_array($id, $participantIds, true)) {
                    $participantIds[] = $id;
                }
            });

        // Event thread ke peserta thread saja
        $this->realtime->publish(
            'messages',
            'thread:message:new',
            $participantIds,
            ['message' => $message->toApiArray(), 'thread_id' => $message->thread_id],
        );

        // Notifikasi ke peserta thread (kecuali pengirim)
        foreach ($participantIds as $userId) {
            if ($userId === $sender->id) {
                continue;
            }

            $threadRoot->load('attachments'); // Pastikan data lengkap
            $this->notifications->createForThreadMention(
                $message,
                $sender->name,
                (string) $conversation->name,
                $userId,
                $threadRoot,
            );
        }
    }

    /**
     * Aturan khusus grup/direct sebelum menyimpan.
     *
     * @param  array<int,int>  $mentionIds
     */
    private function assertGroupRules(Conversation $conversation, User $sender, ?int $replyToId, array $mentionIds, ?int $threadId = null): void
    {
        if (! $conversation->isGroup()) {
            if ($mentionIds !== []) {
                throw ValidationException::withMessages(['mention_ids' => 'Mention hanya tersedia di grup.']);
            }

            if ($threadId !== null) {
                throw ValidationException::withMessages(['thread_id' => 'Thread hanya tersedia di grup.']);
            }

            return;
        }

        // Balasan di grup memakai thread (Fase 9), bukan reply_to_id gaya WhatsApp
        if ($replyToId !== null) {
            throw ValidationException::withMessages(['reply_to_id' => 'Balasan di grup memakai thread.']);
        }

        if ($mentionIds !== []) {
            $members = $conversation->participantIds();

            foreach ($mentionIds as $userId) {
                if ($userId === $sender->id || ! in_array($userId, $members, true)) {
                    throw ValidationException::withMessages([
                        'mention_ids' => 'Hanya anggota grup lain yang dapat di-mention.',
                    ]);
                }
            }
        }
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

        $existing->loadMissing(['replyTo.attachments', 'attachments', 'mentions']);

        if ($conversation->isGroup()) {
            $conversation->loadMissing('activeMembers');
            $existing->setRelation('conversation', $conversation);
        }

        return $existing;
    }
}
