<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Services\GroupService;
use App\Services\UnreadService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pengelolaan grup (kontrak §17).
 * Urutan pemeriksaan: bukan anggota -> 404, bukan pemilik -> 403, input -> 422.
 * Hanya PEMILIK yang boleh mengganti nama, menambah, dan mengeluarkan anggota; anggota boleh keluar.
 */
class GroupController extends Controller
{
    private const WITH = [
        'activeMembers.user',
        'lastMessage.attachments', 'lastMessage.reactions', 'lastMessage.replyTo.attachments', 'lastMessage.mentions',
    ];

    public function __construct(
        private readonly GroupService $groups,
        private readonly UnreadService $unread,
    ) {}

    /** POST /api/v1/groups {name, member_ids[]} */
    public function store(Request $request): JsonResponse
    {
        $me = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:50'],
            'member_ids' => ['required', 'array', 'min:1', 'max:'.(Conversation::MAX_MEMBERS - 1)],
            'member_ids.*' => ['integer', 'distinct', 'exists:users,id', Rule::notIn([$me->id])],
        ], [
            'member_ids.*.not_in' => 'Pembuat otomatis menjadi anggota; jangan masukkan diri sendiri.',
        ]);

        $group = $this->groups->create($me, $data['name'], $data['member_ids']);

        return ApiResponse::success(['conversation' => $this->payload($group->id, $me->id)], 'Grup dibuat', 201);
    }

    /** PATCH /api/v1/groups/{id} {name} */
    public function update(Request $request, int $id): JsonResponse
    {
        $group = $this->ownedGroup($request, $id);

        $data = $request->validate(['name' => ['required', 'string', 'min:1', 'max:50']]);

        $this->groups->rename($group, $data['name']);

        return ApiResponse::success(['conversation' => $this->payload($group->id, $request->user()->id)], 'Nama grup diubah');
    }

    /** POST /api/v1/groups/{id}/members {user_ids[]} */
    public function addMembers(Request $request, int $id): JsonResponse
    {
        $group = $this->ownedGroup($request, $id);

        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:'.(Conversation::MAX_MEMBERS - 1)],
            'user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ]);

        $this->groups->addMembers($group, $data['user_ids']);

        return ApiResponse::success(['conversation' => $this->payload($group->id, $request->user()->id)], 'Anggota ditambahkan');
    }

    /** DELETE /api/v1/groups/{id}/members/{userId} — hanya pemilik; untuk keluar sendiri gunakan /leave */
    public function removeMember(Request $request, int $id, int $userId): JsonResponse
    {
        $group = $this->ownedGroup($request, $id);

        if ($userId === $request->user()->id) {
            return ApiResponse::error('VALIDATION_ERROR', 'Gunakan "keluar grup" untuk keluar sendiri.', 422, [
                'user_id' => ['Gunakan "keluar grup" untuk keluar sendiri.'],
            ]);
        }

        abort_unless($group->activeMembers->contains('user_id', $userId), 404);

        $this->groups->removeMember($group, $userId);

        return ApiResponse::success(['conversation' => $this->payload($group->id, $request->user()->id)], 'Anggota dikeluarkan');
    }

    /** POST /api/v1/groups/{id}/leave */
    public function leave(Request $request, int $id): JsonResponse
    {
        $group = Conversation::forUser($request->user()->id)
            ->where('type', Conversation::TYPE_GROUP)
            ->findOrFail($id);

        $this->groups->leave($group, $request->user());

        return ApiResponse::success(null, 'Anda keluar dari grup');
    }

    /** Grup yang user ini anggotanya DAN pemiliknya; bukan anggota -> 404, anggota biasa -> 403. */
    private function ownedGroup(Request $request, int $id): Conversation
    {
        $me = $request->user()->id;

        $group = Conversation::forUser($me)
            ->where('type', Conversation::TYPE_GROUP)
            ->with('activeMembers.user')
            ->findOrFail($id);

        /** @var ConversationMember|null $membership */
        $membership = $group->activeMembers->firstWhere('user_id', $me);

        abort_unless($membership !== null, 404);

        if (! $membership->isOwner()) {
            abort(403);
        }

        return $group;
    }

    /** @return array<string,mixed> */
    private function payload(int $conversationId, int $viewerId): array
    {
        $group = Conversation::with(self::WITH)->findOrFail($conversationId);

        if ($group->lastMessage !== null) {
            $group->lastMessage->setRelation('conversation', $group);
        }

        return $group->toApiArray($viewerId, $this->unread->forConversation($viewerId, $group->id));
    }
}
