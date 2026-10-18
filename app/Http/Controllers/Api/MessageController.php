<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\MessageService;
use App\Support\ApiResponse;
use App\Support\Iso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Riwayat/resync dan kirim pesan (kontrak §D.4, §D.5). */
class MessageController extends Controller
{
    private const RESYNC_LIMIT = 200;

    public function __construct(private readonly MessageService $messages) {}

    public function index(Request $request, int $id): JsonResponse
    {
        // Bukan peserta -> 404 (anti-IDOR, kontrak §D.0)
        $conversation = Conversation::forUser($request->user()->id)->findOrFail($id);

        $validated = $request->validate([
            'before_id' => ['nullable', 'integer', 'min:1', 'prohibits:updated_since'],
            'updated_since' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $base = Message::withTrashed()
            ->where('conversation_id', $conversation->id)
            ->with(['replyTo.attachments', 'reactions', 'attachments', 'mentions']);

        $hasMoreBefore = false;
        $hasMore = false;

        if (! empty($validated['updated_since'])) {
            // Mode resync (kontrak §H.6)
            $since = Carbon::parse($validated['updated_since'])->setTimezone(config('app.timezone'));

            $rows = (clone $base)
                ->where('updated_at', '>=', $since)
                ->orderBy('updated_at')
                ->orderBy('id')
                ->limit(self::RESYNC_LIMIT + 1)
                ->get();

            $hasMore = $rows->count() > self::RESYNC_LIMIT;
            $rows = $rows->take(self::RESYNC_LIMIT)->sortBy('id')->values();
        } else {
            // Mode riwayat: halaman terbaru, atau sebelum before_id
            $limit = (int) ($validated['limit'] ?? 30);

            $rows = (clone $base)
                ->when(isset($validated['before_id']), fn ($q) => $q->where('id', '<', $validated['before_id']))
                ->orderByDesc('id')
                ->limit($limit + 1)
                ->get();

            $hasMoreBefore = $rows->count() > $limit;
            $rows = $rows->take($limit)->sortBy('id')->values(); // urut lama -> baru
        }

        // Pesan grup butuh daftar anggota (kursor baca) untuk `read_by_count`: dimuat SEKALI per permintaan
        if ($conversation->isGroup()) {
            $conversation->load('activeMembers');
            $rows->each(fn (Message $m) => $m->setRelation('conversation', $conversation));
        }

        return ApiResponse::success(
            ['messages' => $rows->map(fn (Message $m) => $m->toApiArray())->values()->all()],
            'OK',
            200,
            [
                'has_more_before' => $hasMoreBefore,
                'oldest_id' => $rows->first()?->id,
                'newest_id' => $rows->last()?->id,
                'has_more' => $hasMore,
                'server_time' => Iso::format(now()),
            ],
        );
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $conversation = Conversation::forUser($user->id)->findOrFail($id);

        $data = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            // Teks boleh kosong bila ada lampiran, dan sebaliknya (kontrak §D.5)
            'body' => ['nullable', 'string', 'max:'.config('chat.message_max_length'), 'required_without:attachment_ids'],
            'attachment_ids' => ['nullable', 'array', 'max:'.config('chat.limits.attachments_per_message')],
            'attachment_ids.*' => ['integer', 'min:1', 'distinct'],
            // Mention: hanya grup, hanya anggota lain (diperiksa di MessageService, kontrak §17)
            'mention_ids' => ['nullable', 'array', 'max:20'],
            'mention_ids.*' => ['integer', 'min:1', 'distinct'],
            'reply_to_id' => [
                'nullable',
                'integer',
                Rule::exists('messages', 'id')
                    ->where('conversation_id', $conversation->id)
                    ->whereNull('deleted_at'),
            ],
        ]);

        [$message, $created] = $this->messages->send(
            $conversation,
            $user,
            $data['client_uuid'],
            $data['body'] ?? null,
            isset($data['reply_to_id']) ? (int) $data['reply_to_id'] : null,
            $data['attachment_ids'] ?? [],
            $data['mention_ids'] ?? [],
        );

        return ApiResponse::success(
            ['message' => $message->toApiArray()],
            $created ? 'Pesan terkirim' : 'OK',
            $created ? 201 : 200,
        );
    }
}
