<?php

namespace App\Services;

use App\Models\ChatNotification;
use App\Models\Message;
use App\Realtime\RealtimePublisher;

/**
 * Pembuatan, penghitungan, dan penandaan notifikasi (kontrak §D.8, §H.5).
 * Database = sumber kebenaran; Redis hanya jalur distribusi event.
 */
class NotificationService
{
    public const TYPE_CHAT_MESSAGE = 'chat.message';

    public const TYPE_CHAT_MENTION = 'chat.mention';

    public const TYPE_CHAT_MENTION_THREAD = 'chat.mention_thread';

    public function __construct(private readonly RealtimePublisher $realtime) {}

    /** Buat notifikasi untuk penerima pesan. Panggil DI DALAM transaksi penyimpanan pesan. */
    public function createForMessage(Message $message, string $senderName): ChatNotification
    {
        $preview = mb_substr(trim((string) $message->body), 0, 100);

        return ChatNotification::create([
            'user_id' => $message->recipient_id,
            'type' => self::TYPE_CHAT_MESSAGE,
            'title' => 'Pesan baru dari '.$senderName,
            'body' => $preview !== '' ? $preview : 'Mengirim lampiran',
            'data' => [
                'conversation_id' => $message->conversation_id,
                'message_id' => $message->id,
                'sender_id' => $message->sender_id,
                'url' => '/chat?conversation='.$message->conversation_id,
            ],
        ]);
    }

    /** Notifikasi "disebut" untuk anggota grup yang di-@mention. Panggil DI DALAM transaksi pesan. */
    public function createForMention(Message $message, string $senderName, string $groupName, int $userId): ChatNotification
    {
        $preview = mb_substr(trim((string) $message->body), 0, 100);

        return ChatNotification::create([
            'user_id' => $userId,
            'type' => self::TYPE_CHAT_MENTION,
            'title' => $senderName.' menyebut Anda di '.$groupName,
            'body' => $preview !== '' ? $preview : 'Mengirim lampiran',
            'data' => [
                'conversation_id' => $message->conversation_id,
                'message_id' => $message->id,
                'sender_id' => $message->sender_id,
                'url' => '/chat?conversation='.$message->conversation_id,
            ],
        ]);
    }

    /** Notifikasi mention di thread. Panggil DI DALAM transaksi pesan. */
    public function createForThreadMention(Message $message, string $senderName, string $groupName, int $userId, Message $threadRoot): ChatNotification
    {
        $preview = mb_substr(trim((string) $message->body), 0, 100);

        return ChatNotification::create([
            'user_id' => $userId,
            'type' => self::TYPE_CHAT_MENTION_THREAD,
            'title' => $senderName.' membalas di thread '.$groupName,
            'body' => $preview !== '' ? $preview : 'Mengirim lampiran',
            'data' => [
                'conversation_id' => $message->conversation_id,
                'message_id' => $message->id,
                'thread_id' => $message->thread_id,
                'sender_id' => $message->sender_id,
                'url' => '/chat?conversation='.$message->conversation_id.'&thread='.$message->thread_id,
            ],
        ]);
    }

    /**
     * Tandai notifikasi mention pada grup ini (pesan id <= $upToMessageId) sebagai terbaca.
     *
     * @return array<int,string> id notifikasi yang berubah
     */
    public function markMentionsRead(int $userId, int $conversationId, int $upToMessageId): array
    {
        $matched = ChatNotification::query()
            ->where('user_id', $userId)
            ->where('type', self::TYPE_CHAT_MENTION)
            ->whereNull('read_at')
            ->get()
            ->filter(fn (ChatNotification $n) => (int) ($n->data['conversation_id'] ?? 0) === $conversationId
                && (int) ($n->data['message_id'] ?? 0) <= $upToMessageId);

        if ($matched->isEmpty()) {
            return [];
        }

        $now = now();
        ChatNotification::whereIn('id', $matched->pluck('id'))->update(['read_at' => $now, 'updated_at' => $now]);

        return $matched->pluck('id')->values()->all();
    }

    public function unreadCount(int $userId): int
    {
        return ChatNotification::where('user_id', $userId)->whereNull('read_at')->count();
    }

    /** Kirim `notification:new` (ACK dihitung dari penerima). Panggil SETELAH commit. */
    public function publishNew(ChatNotification $notification): void
    {
        $this->realtime->publish(
            'notifications',
            'notification:new',
            [$notification->user_id],
            [
                'notification' => $notification->toApiArray(),
                'unread_count' => $this->unreadCount($notification->user_id),
            ],
            $notification->user_id,
        );
    }

    /** Beri tahu semua tab user bahwa notifikasi berubah menjadi terbaca. */
    public function publishRead(int $userId, ?array $ids, bool $all): void
    {
        $this->realtime->publish('notifications', 'notification:read', [$userId], [
            'ids' => $ids,
            'all' => $all,
            'unread_count' => $this->unreadCount($userId),
        ]);
    }

    /**
     * Tandai notifikasi chat.message yang terkait pesan-pesan ini sebagai terbaca.
     *
     * @param  array<int,int>  $messageIds
     * @return array<int,string> id notifikasi yang berubah
     */
    public function markForMessages(int $userId, array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        $matched = ChatNotification::query()
            ->where('user_id', $userId)
            ->where('type', self::TYPE_CHAT_MESSAGE)
            ->whereNull('read_at')
            ->get()
            ->filter(fn (ChatNotification $n) => in_array((int) ($n->data['message_id'] ?? 0), $messageIds, true));

        if ($matched->isEmpty()) {
            return [];
        }

        $now = now();
        ChatNotification::whereIn('id', $matched->pluck('id'))->update(['read_at' => $now, 'updated_at' => $now]);

        return $matched->pluck('id')->values()->all();
    }
}
