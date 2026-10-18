<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use App\Models\User;
use App\Realtime\RealtimePublisher;
use App\Support\Iso;
use Illuminate\Support\Facades\DB;

/**
 * Menandai pesan sebagai dibaca (kontrak §D.7, §H.2, §17). Idempoten.
 *  - direct: read_at pada baris pesan, notifikasi terkait ikut terbaca.
 *  - grup  : kursor baca anggota (last_read_message_id) yang hanya bergerak maju.
 */
class ReadReceiptService
{
    public function __construct(
        private readonly RealtimePublisher $realtime,
        private readonly UnreadService $unread,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @return array{conversation_id:int,read_count:int,unread_count:int,total_unread:int,read_at:?string}
     */
    public function markRead(Conversation $conversation, User $reader, int $upToMessageId): array
    {
        if ($conversation->isGroup()) {
            return $this->markReadGroup($conversation, $reader, $upToMessageId);
        }

        $now = now();

        [$messageIds, $notificationIds] = DB::transaction(function () use ($conversation, $reader, $upToMessageId, $now) {
            // Hanya pesan MILIK pembaca (recipient) di percakapan ini yang belum dibaca
            $ids = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('recipient_id', $reader->id)
                ->where('id', '<=', $upToMessageId)
                ->whereNull('read_at')
                ->pluck('id');

            if ($ids->isEmpty()) {
                return [[], []];
            }

            // dibaca => pasti sudah diterima (delivered)
            Message::whereIn('id', $ids)->whereNull('delivered_at')->update(['delivered_at' => $now]);

            Message::whereIn('id', $ids)->update([
                'read_at' => $now,
                'updated_at' => $now,
                'revision' => DB::raw('revision + 1'),
            ]);

            $messageIds = $ids->map(fn ($id) => (int) $id)->all();

            return [$messageIds, $this->notifications->markForMessages($reader->id, $messageIds)];
        });

        $readCount = count($messageIds);
        $unreadCount = $this->unread->forConversation($reader->id, $conversation->id);
        $totalUnread = $this->unread->total($reader->id);

        if ($readCount > 0) {
            $peerId = $conversation->peerIdFor($reader->id);

            // Pengirim melihat centang "dibaca"
            $this->realtime->publish('messages', 'message:read', [$peerId], [
                'conversation_id' => $conversation->id,
                'reader_id' => $reader->id,
                'up_to_message_id' => $upToMessageId,
                'read_at' => Iso::format($now),
            ]);

            // Semua tab pembaca memperbarui badge pesan
            $this->realtime->publish('messages', 'unread:update', [$reader->id], [
                'conversation_id' => $conversation->id,
                'unread_count' => $unreadCount,
                'total_unread' => $totalUnread,
            ]);

            // ...dan badge notifikasi
            if ($notificationIds !== []) {
                $this->notifications->publishRead($reader->id, $notificationIds, false);
            }
        }

        return [
            'conversation_id' => $conversation->id,
            'read_count' => $readCount,
            'unread_count' => $unreadCount,
            'total_unread' => $totalUnread,
            // null bila tidak ada pesan yang berubah (panggilan ulang)
            'read_at' => $readCount > 0 ? Iso::format($now) : null,
        ];
    }

    /** @return array{conversation_id:int,read_count:int,unread_count:int,total_unread:int,read_at:?string} */
    private function markReadGroup(Conversation $conversation, User $reader, int $upToMessageId): array
    {
        $member = ConversationMember::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $reader->id)
            ->whereNull('left_at')
            ->firstOrFail();

        $from = (int) $member->last_read_message_id;
        $now = now();

        // Kursor hanya maju: membaca "sampai id yang lebih kecil" tidak mengubah apa pun
        $advanced = $upToMessageId > $from
            && ConversationMember::whereKey($member->id)
                ->where('last_read_message_id', '<', $upToMessageId)
                ->update(['last_read_message_id' => $upToMessageId, 'updated_at' => $now]) > 0;

        $readCount = 0;
        $notificationIds = [];

        if ($advanced) {
            $readCount = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('id', '>', $from)
                ->where('id', '<=', $upToMessageId)
                ->where('sender_id', '!=', $reader->id)
                ->count();

            $notificationIds = $this->notifications->markMentionsRead($reader->id, $conversation->id, $upToMessageId);
        }

        $unreadCount = $this->unread->forConversation($reader->id, $conversation->id);
        $totalUnread = $this->unread->total($reader->id);

        if ($advanced) {
            $others = array_values(array_filter(
                $conversation->participantIds(),
                fn (int $id) => $id !== $reader->id,
            ));

            // Anggota lain menambah hitungan "dibaca oleh" untuk pesan (from, up_to] (kontrak §17)
            if ($others !== []) {
                $this->realtime->publish('messages', 'group:read', $others, [
                    'conversation_id' => $conversation->id,
                    'reader_id' => $reader->id,
                    'from_message_id' => $from,
                    'up_to_message_id' => $upToMessageId,
                    'read_at' => Iso::format($now),
                ]);
            }

            // Semua tab pembaca memperbarui badge
            $this->realtime->publish('messages', 'unread:update', [$reader->id], [
                'conversation_id' => $conversation->id,
                'unread_count' => $unreadCount,
                'total_unread' => $totalUnread,
            ]);

            if ($notificationIds !== []) {
                $this->notifications->publishRead($reader->id, $notificationIds, false);
            }
        }

        return [
            'conversation_id' => $conversation->id,
            'read_count' => $readCount,
            'unread_count' => $unreadCount,
            'total_unread' => $totalUnread,
            'read_at' => $advanced ? Iso::format($now) : null,
        ];
    }
}
