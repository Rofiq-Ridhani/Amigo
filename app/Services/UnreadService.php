<?php

namespace App\Services;

use App\Models\ConversationMember;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * Perhitungan unread lewat query (kontrak §C.8, §17).
 *  - direct: recipient_id = user AND read_at IS NULL AND deleted_at IS NULL
 *  - grup  : pesan orang lain dengan id > kursor baca anggota (conversation_members.last_read_message_id)
 * Pesan terhapus tidak pernah dihitung (soft delete).
 */
class UnreadService
{
    public function forConversation(int $userId, int $conversationId): int
    {
        $member = ConversationMember::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->first();

        if ($member !== null) {
            return Message::query()
                ->where('conversation_id', $conversationId)
                ->where('id', '>', $member->last_read_message_id)
                ->where('sender_id', '!=', $userId)
                ->count();
        }

        return Message::query()
            ->where('recipient_id', $userId)
            ->where('conversation_id', $conversationId)
            ->whereNull('read_at')
            ->count();
    }

    public function total(int $userId): int
    {
        $direct = Message::query()
            ->where('recipient_id', $userId)
            ->whereNull('read_at')
            ->count();

        return $direct + $this->groupBase($userId)->count();
    }

    /**
     * Hanya percakapan dengan unread > 0, urut conversation_id (direct dan grup digabung).
     *
     * @return array<int,array{conversation_id:int,unread_count:int}>
     */
    public function byConversation(int $userId): array
    {
        $counts = [];

        $direct = Message::query()
            ->where('recipient_id', $userId)
            ->whereNull('read_at')
            ->selectRaw('conversation_id, count(*) as total')
            ->groupBy('conversation_id')
            ->get();

        foreach ($direct as $row) {
            $counts[(int) $row->conversation_id] = (int) $row->total;
        }

        $groups = $this->groupBase($userId)
            ->selectRaw('messages.conversation_id as conversation_id, count(*) as total')
            ->groupBy('messages.conversation_id')
            ->get();

        foreach ($groups as $row) {
            $counts[(int) $row->conversation_id] = (int) $row->total;
        }

        ksort($counts);

        $result = [];
        foreach ($counts as $conversationId => $total) {
            $result[] = ['conversation_id' => $conversationId, 'unread_count' => $total];
        }

        return $result;
    }

    /**
     * Peta conversation_id => unread untuk beberapa percakapan sekaligus (dipakai daftar percakapan).
     *
     * @param  iterable<int>  $conversationIds
     * @return array<int,int>
     */
    public function countsFor(int $userId, iterable $conversationIds): array
    {
        $wanted = array_flip(array_map('intval', is_array($conversationIds) ? $conversationIds : iterator_to_array($conversationIds)));
        $map = [];

        foreach ($this->byConversation($userId) as $row) {
            if (isset($wanted[$row['conversation_id']])) {
                $map[$row['conversation_id']] = $row['unread_count'];
            }
        }

        return $map;
    }

    /** Pesan grup yang belum dibaca user (basis query, tanpa select). */
    private function groupBase(int $userId)
    {
        return DB::table('messages')
            ->join('conversation_members as cm', function ($join) use ($userId) {
                $join->on('cm.conversation_id', '=', 'messages.conversation_id')
                    ->where('cm.user_id', '=', $userId)
                    ->whereNull('cm.left_at');
            })
            ->whereNull('messages.deleted_at')
            ->where('messages.sender_id', '!=', $userId)
            ->whereColumn('messages.id', '>', 'cm.last_read_message_id');
    }
}
