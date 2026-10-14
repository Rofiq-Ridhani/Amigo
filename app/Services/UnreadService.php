<?php

namespace App\Services;

use App\Models\Message;

/**
 * Perhitungan unread lewat query (kontrak §C.8):
 *   recipient_id = user AND read_at IS NULL AND deleted_at IS NULL
 * (pesan terhapus otomatis tidak ikut karena soft delete).
 */
class UnreadService
{
    public function forConversation(int $userId, int $conversationId): int
    {
        return Message::query()
            ->where('recipient_id', $userId)
            ->where('conversation_id', $conversationId)
            ->whereNull('read_at')
            ->count();
    }

    public function total(int $userId): int
    {
        return Message::query()
            ->where('recipient_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Hanya percakapan dengan unread > 0, urut conversation_id.
     *
     * @return array<int,array{conversation_id:int,unread_count:int}>
     */
    public function byConversation(int $userId): array
    {
        return Message::query()
            ->where('recipient_id', $userId)
            ->whereNull('read_at')
            ->selectRaw('conversation_id, count(*) as total')
            ->groupBy('conversation_id')
            ->orderBy('conversation_id')
            ->get()
            ->map(fn ($row) => [
                'conversation_id' => (int) $row->conversation_id,
                'unread_count' => (int) $row->total,
            ])
            ->all();
    }
}
