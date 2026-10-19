<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\ApiResponse;
use App\Support\Iso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Riwayat thread (kontrak §18). */
class ThreadController extends Controller
{
    private const RESYNC_LIMIT = 200;

    public function show(Request $request, int $conversationId, int $threadRootId): JsonResponse
    {
        // Bukan peserta -> 404 (anti-IDOR)
        $conversation = Conversation::forUser($request->user()->id)->findOrFail($conversationId);

        // Pesan asal thread harus ada dan tidak boleh balasan thread
        $threadRoot = Message::withTrashed()
            ->where('conversation_id', $conversationId)
            ->where('id', $threadRootId)
            ->whereNull('thread_id')
            ->firstOrFail();

        $validated = $request->validate([
            'before_id' => ['nullable', 'integer', 'min:1', 'prohibits:updated_since'],
            'updated_since' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Riwayat balasan dalam thread (tidak termasuk pesan asal)
        $base = Message::withTrashed()
            ->where('conversation_id', $conversationId)
            ->where('thread_id', $threadRootId)
            ->with(['replyTo.attachments', 'reactions', 'attachments', 'mentions']);

        $hasMoreBefore = false;
        $hasMore = false;

        if (! empty($validated['updated_since'])) {
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
            $limit = (int) ($validated['limit'] ?? 30);

            $rows = (clone $base)
                ->when(isset($validated['before_id']), fn ($q) => $q->where('id', '<', $validated['before_id']))
                ->orderByDesc('id')
                ->limit($limit + 1)
                ->get();

            $hasMoreBefore = $rows->count() > $limit;
            $rows = $rows->take($limit)->sortBy('id')->values();
        }

        $conversation->load('activeMembers');
        $rows->each(fn (Message $m) => $m->setRelation('conversation', $conversation));

        return ApiResponse::success(
            [
                'thread_root' => $threadRoot->toApiArray(),
                'messages' => $rows->map(fn (Message $m) => $m->toApiArray())->values()->all(),
            ],
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
}
