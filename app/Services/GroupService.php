<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use App\Models\User;
use App\Realtime\RealtimePublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pengelolaan grup (kontrak §17): buat, ganti nama, tambah/keluarkan anggota, keluar.
 * Setiap perubahan dipublish sebagai `conversation:updated` / `conversation:removed`;
 * client lalu menyegarkan percakapan lewat GET /api/v1/conversations/{id}.
 */
class GroupService
{
    public function __construct(private readonly RealtimePublisher $realtime) {}

    /** @param  array<int,int>  $memberIds  anggota selain pembuat */
    public function create(User $owner, string $name, array $memberIds): Conversation
    {
        $memberIds = array_values(array_unique(array_diff(array_map('intval', $memberIds), [$owner->id])));

        if (count($memberIds) + 1 > Conversation::MAX_MEMBERS) {
            throw ValidationException::withMessages([
                'member_ids' => 'Grup maksimal '.Conversation::MAX_MEMBERS.' anggota.',
            ]);
        }

        $conversation = DB::transaction(function () use ($owner, $name, $memberIds) {
            $conversation = Conversation::create([
                'type' => Conversation::TYPE_GROUP,
                'name' => $name,
                'created_by' => $owner->id,
            ]);

            ConversationMember::create([
                'conversation_id' => $conversation->id,
                'user_id' => $owner->id,
                'role' => ConversationMember::ROLE_OWNER,
                'last_read_message_id' => 0,
                'joined_at' => now(),
            ]);

            foreach ($memberIds as $userId) {
                ConversationMember::create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $userId,
                    'role' => ConversationMember::ROLE_MEMBER,
                    'last_read_message_id' => 0,
                    'joined_at' => now(),
                ]);
            }

            return $conversation;
        });

        $this->publishUpdated($conversation, 'created');

        return $conversation;
    }

    public function rename(Conversation $conversation, string $name): void
    {
        if ($conversation->name === $name) {
            return;
        }

        $conversation->forceFill(['name' => $name])->save();

        $this->publishUpdated($conversation, 'renamed');
    }

    /**
     * Tambah anggota. Yang sudah aktif dilewati; yang pernah keluar diaktifkan kembali.
     * Riwayat grup terlihat penuh (keputusan #3); badge unread mulai dari 0 (kursor = pesan terakhir saat ini).
     *
     * @param  array<int,int>  $userIds
     * @return array<int,int> id yang benar-benar ditambahkan
     */
    public function addMembers(Conversation $conversation, array $userIds): array
    {
        $active = $conversation->participantIds();
        $new = array_values(array_diff(array_unique(array_map('intval', $userIds)), $active));

        if ($new === []) {
            return [];
        }

        if (count($active) + count($new) > Conversation::MAX_MEMBERS) {
            throw ValidationException::withMessages([
                'user_ids' => 'Grup maksimal '.Conversation::MAX_MEMBERS.' anggota.',
            ]);
        }

        $latest = (int) (Message::withTrashed()->where('conversation_id', $conversation->id)->max('id') ?? 0);

        DB::transaction(function () use ($conversation, $new, $latest) {
            foreach ($new as $userId) {
                ConversationMember::updateOrCreate(
                    ['conversation_id' => $conversation->id, 'user_id' => $userId],
                    [
                        'role' => ConversationMember::ROLE_MEMBER,
                        'last_read_message_id' => $latest,
                        'joined_at' => now(),
                        'left_at' => null,
                    ],
                );
            }
        });

        $this->publishUpdated($conversation, 'members_added');

        return $new;
    }

    /** Pemilik mengeluarkan anggota. */
    public function removeMember(Conversation $conversation, int $userId): void
    {
        $this->deactivate($conversation, $userId, 'member_removed');
    }

    /** Anggota keluar sendiri. Bila pemilik keluar, kepemilikan diberikan kepada anggota aktif paling lama. */
    public function leave(Conversation $conversation, User $user): void
    {
        $member = ConversationMember::where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->firstOrFail();

        if ($member->isOwner()) {
            $successor = ConversationMember::where('conversation_id', $conversation->id)
                ->where('user_id', '!=', $user->id)
                ->whereNull('left_at')
                ->orderBy('joined_at')->orderBy('id')
                ->first();

            $successor?->update(['role' => ConversationMember::ROLE_OWNER]);
            $member->update(['role' => ConversationMember::ROLE_MEMBER]);
        }

        $this->deactivate($conversation, $user->id, 'member_left');
    }

    private function deactivate(Conversation $conversation, int $userId, string $reason): void
    {
        ConversationMember::where('conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->firstOrFail()
            ->update(['left_at' => now()]);

        // Yang keluar (semua tabnya) tahu bahwa grup ini tidak lagi miliknya
        $this->realtime->publish('messages', 'conversation:removed', [$userId], [
            'conversation_id' => $conversation->id,
        ]);

        $this->publishUpdated($conversation, $reason);
    }

    private function publishUpdated(Conversation $conversation, string $reason): void
    {
        $members = $conversation->participantIds();

        if ($members === []) {
            return;
        }

        $this->realtime->publish('messages', 'conversation:updated', $members, [
            'conversation_id' => $conversation->id,
            'reason' => $reason, // created | renamed | members_added | member_removed | member_left
        ]);
    }
}
