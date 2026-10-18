<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\UnreadService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Daftar, detail, dan mulai percakapan direct (kontrak §D.4, §17). */
class ConversationController extends Controller
{
    /** Relasi yang dibutuhkan untuk membentuk payload Conversation (direct maupun grup). */
    private const WITH = [
        'userOne', 'userTwo', 'activeMembers.user',
        'lastMessage.attachments', 'lastMessage.reactions', 'lastMessage.replyTo.attachments', 'lastMessage.mentions',
    ];

    public function __construct(private readonly UnreadService $unread) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $me = $request->user()->id;
        $perPage = (int) ($validated['per_page'] ?? 20);

        $paginator = Conversation::forUser($me)
            // direct: hanya yang sudah punya pesan; grup: selalu tampil (juga yang masih kosong)
            ->where(function ($q) {
                $q->whereNotNull('last_message_id')->orWhere('type', Conversation::TYPE_GROUP);
            })
            ->with(self::WITH)
            ->orderByRaw('coalesce(last_message_at, created_at) desc')
            ->orderByDesc('id')
            ->paginate($perPage);

        // Unread dihitung lewat query (kontrak §C.8)
        $unread = $this->unread->countsFor($me, $paginator->getCollection()->pluck('id')->all());

        $items = $paginator->getCollection()
            ->map(fn (Conversation $c) => $this->payload($c, $me, (int) ($unread[$c->id] ?? 0)))
            ->values()
            ->all();

        return ApiResponse::success(['conversations' => $items], 'OK', 200, [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    /** GET /api/v1/conversations/{id} — dipakai Frontend untuk menyegarkan satu percakapan (mis. setelah conversation:updated). */
    public function show(Request $request, int $id): JsonResponse
    {
        $me = $request->user()->id;

        // Bukan peserta -> 404 (anti-IDOR, kontrak §D.0)
        $conversation = Conversation::forUser($me)->with(self::WITH)->findOrFail($id);

        return ApiResponse::success([
            'conversation' => $this->payload($conversation, $me, $this->unread->forConversation($me, $conversation->id)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $me = $request->user();

        $data = $request->validate(
            ['user_id' => ['required', 'integer', 'exists:users,id', Rule::notIn([$me->id])]],
            ['user_id.not_in' => 'Tidak bisa memulai percakapan dengan diri sendiri.'],
        );

        $conversation = Conversation::between($me->id, (int) $data['user_id']);
        $created = $conversation->wasRecentlyCreated;

        $conversation->load(self::WITH);

        return ApiResponse::success(
            ['conversation' => $this->payload($conversation, $me->id, $this->unread->forConversation($me->id, $conversation->id))],
            $created ? 'Percakapan dibuat' : 'OK',
            $created ? 201 : 200,
        );
    }

    /** @return array<string,mixed> */
    private function payload(Conversation $conversation, int $viewerId, int $unread): array
    {
        // Pesan terakhir pada grup butuh anggota untuk menghitung `read_by_count`
        if ($conversation->isGroup() && $conversation->lastMessage !== null) {
            $conversation->lastMessage->setRelation('conversation', $conversation);
        }

        return $conversation->toApiArray($viewerId, $unread);
    }
}
