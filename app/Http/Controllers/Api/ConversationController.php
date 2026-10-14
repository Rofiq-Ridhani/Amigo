<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Daftar percakapan & mulai percakapan (kontrak §D.4). */
class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $me = $request->user()->id;
        $perPage = (int) ($validated['per_page'] ?? 20);

        $paginator = Conversation::forUser($me)
            ->whereNotNull('last_message_id') // hanya percakapan yang sudah punya pesan
            ->with(['userOne', 'userTwo', 'lastMessage'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $ids = $paginator->getCollection()->pluck('id');

        // Unread dihitung lewat query (kontrak §C.8)
        $unread = Message::query()
            ->where('recipient_id', $me)
            ->whereNull('read_at')
            ->whereIn('conversation_id', $ids)
            ->selectRaw('conversation_id, count(*) as total')
            ->groupBy('conversation_id')
            ->pluck('total', 'conversation_id');

        $items = $paginator->getCollection()
            ->map(fn (Conversation $c) => $c->toApiArray($me, (int) ($unread[$c->id] ?? 0)))
            ->values()
            ->all();

        return ApiResponse::success(['conversations' => $items], 'OK', 200, [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
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

        $conversation->load(['userOne', 'userTwo', 'lastMessage']);

        $unread = Message::where('conversation_id', $conversation->id)
            ->where('recipient_id', $me->id)
            ->whereNull('read_at')
            ->count();

        return ApiResponse::success(
            ['conversation' => $conversation->toApiArray($me->id, $unread)],
            $created ? 'Percakapan dibuat' : 'OK',
            $created ? 201 : 200,
        );
    }
}
