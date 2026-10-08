<?php

namespace App\Http\Controllers;

use App\Events\MessageSent;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MessageController extends Controller
{
    /**
     * POST /messages — contract §5.2. Body: recipient_id, body, reply_to_id?.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'recipient_id' => ['required', 'integer', 'exists:users,id', 'not_in:' . Auth::id()],
            'body' => ['required', 'string', 'max:1000'],
            'reply_to_id' => ['nullable', 'integer', 'exists:messages,id'],
        ]);

        if (! empty($validated['reply_to_id'])) {
            $parent = Message::find($validated['reply_to_id']);
            $belongs = ($parent->sender_id === Auth::id() && $parent->recipient_id === $validated['recipient_id'])
                || ($parent->sender_id === $validated['recipient_id'] && $parent->recipient_id === Auth::id());
            if (! $belongs) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pesan yang direply tidak dalam percakapan ini',
                ], 422);
            }
        }

        $msg = Message::create([
            'sender_id' => Auth::id(),
            'recipient_id' => $validated['recipient_id'],
            'body' => $validated['body'],
            'reply_to_id' => $validated['reply_to_id'] ?? null,
        ]);

        broadcast(new MessageSent($msg))->toOthers();

        $msg->load(['sender:id,name', 'recipient:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'Pesan terkirim',
            'data' => $this->serialize($msg),
        ], 201);
    }

    /**
     * GET /api/conversations/{userId}/messages — contract §5.3. Pagination page/per_page + before_id.
     */
    public function index(Request $request, int $userId): JsonResponse
    {
        if ($userId === (int) Auth::id()) {
            return response()->json(['success' => false, 'message' => 'Tidak bisa buka percakapan dengan diri sendiri'], 403);
        }
        if (! User::where('id', $userId)->exists()) {
            return response()->json(['success' => false, 'message' => 'User tidak ditemukan'], 404);
        }

        $perPage = min(max((int) $request->integer('per_page', 50), 1), 100);
        $page = max((int) $request->integer('page', 1), 1);
        $beforeId = $request->integer('before_id');

        $q = Message::query()
            ->where(function ($qq) use ($userId) {
                $qq->where(fn ($a) => $a->where('sender_id', Auth::id())->where('recipient_id', $userId))
                    ->orWhere(fn ($a) => $a->where('sender_id', $userId)->where('recipient_id', Auth::id()));
            })
            ->when($beforeId, fn ($qq) => $qq->where('id', '<', $beforeId))
            ->orderByDesc('id');

        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()->reverse()->values();
        $rows->load(['sender:id,name', 'replyTo:id,message,sender_id', 'replyTo.sender:id,name', 'reactions', 'attachments']);

        return response()->json([
            'success' => true,
            'data' => $rows->map(fn (Message $m) => $this->serialize($m))->values(),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * POST /api/conversations/{userId}/mark-read — contract §5.7.
     */
    public function markRead(Request $request, int $userId): JsonResponse
    {
        $request->validate(['up_to_message_id' => ['nullable', 'integer', 'exists:messages,id']]);

        $q = Message::where('sender_id', $userId)->where('recipient_id', Auth::id())->whereNull('read_at');
        if ($request->filled('up_to_message_id')) {
            $q->where('id', '<=', $request->integer('up_to_message_id'));
        }
        $q->update(['read_at' => now()]);

        $unread = Message::where('sender_id', $userId)->where('recipient_id', Auth::id())->whereNull('read_at')->count();

        // ponytail: emit message:read ke Reverb channel si pengirim — next step be-socket/presence

        return response()->json([
            'success' => true,
            'message' => 'Pesan ditandai dibaca',
            'data' => ['unread_count' => $unread],
        ]);
    }

    /**
     * GET /api/unread-count — contract §5.8.
     */
    public function unreadCount(): JsonResponse
    {
        $rows = Message::where('recipient_id', Auth::id())->whereNull('read_at')
            ->selectRaw('sender_id, COUNT(*) as c')->groupBy('sender_id')->get();

        $convs = [];
        $total = 0;
        foreach ($rows as $r) {
            $convs[(string) $r->sender_id] = (int) $r->c;
            $total += (int) $r->c;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'total_unread' => $total,
                'conversations' => $convs,
            ],
        ]);
    }

    /**
     * GET /api/users — contract §5.1 (search, online_only).
     */
    public function users(Request $request): JsonResponse
    {
        $q = User::where('id', '!=', Auth::id());
        if ($request->filled('search')) {
            $s = '%' . $request->string('search') . '%';
            $q->where(fn ($qq) => $qq->where('name', 'like', $s)->orWhere('email', 'like', $s));
        }
        // ponytail: online_only pakai last_seen_at > now-2min, tanpa Redis presence dulu
        if ($request->boolean('online_only')) {
            $q->where('last_seen_at', '>', now()->subMinutes(2));
        }

        $users = $q->orderBy('name')->get(['id', 'name', 'email', 'avatar', 'last_seen_at']);

        return response()->json([
            'success' => true,
            'data' => $users->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'avatar' => $u->avatar,
                'status' => $u->last_seen_at && $u->last_seen_at->gt(now()->subMinutes(2)) ? 'online' : 'offline',
                'last_seen_at' => $u->last_seen_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    private function serialize(Message $m): array
    {
        $reply = null;
        if ($m->reply_to_id && $m->relationLoaded('replyTo') && $m->replyTo) {
            $reply = [
                'id' => $m->replyTo->id,
                'body' => $m->replyTo->is_deleted ? '[Pesan dihapus]' : ($m->replyTo->body ?? ''),
                'sender_name' => $m->replyTo->sender?->name ?? '—',
            ];
        } elseif ($m->reply_to_id) {
            $parent = Message::with('sender:id,name')->find($m->reply_to_id);
            if ($parent) {
                $reply = [
                    'id' => $parent->id,
                    'body' => $parent->is_deleted ? '[Pesan dihapus]' : $parent->body,
                    'sender_name' => $parent->sender->name ?? '—',
                ];
            }
        }

        // reactions grouped: [{emoji,count,users:[ids]}]
        $reactions = [];
        if ($m->relationLoaded('reactions')) {
            $grouped = $m->reactions->groupBy('emoji');
            foreach ($grouped as $emoji => $rows) {
                $reactions[] = [
                    'emoji' => $emoji,
                    'count' => $rows->count(),
                    'users' => $rows->pluck('user_id')->values()->all(),
                ];
            }
        }

        $attachments = [];
        if ($m->relationLoaded('attachments')) {
            foreach ($m->attachments as $a) {
                $attachments[] = [
                    'id' => $a->id,
                    'type' => $a->type,
                    'url' => $a->file_path ? '/storage/' . ltrim($a->file_path, '/') : null,
                    'thumbnail_url' => $a->thumbnail_path ? '/storage/' . ltrim($a->thumbnail_path, '/') : null,
                    'file_name' => $a->original_name,
                    'file_size' => $a->file_size,
                    'mime_type' => $a->mime_type,
                ];
            }
        }

        $isDeleted = (bool) ($m->is_deleted ?? false);

        return [
            'id' => $m->id,
            'sender_id' => $m->sender_id,
            'sender_name' => $m->sender?->name ?? $m->relationLoaded('sender') ? ($m->sender->name ?? null) : null,
            'recipient_id' => $m->recipient_id,
            'body' => $isDeleted ? '[Pesan dihapus]' : ($m->body ?? ''),
            'reply_to_id' => $m->reply_to_id,
            'reply_to_preview' => $reply,
            'attachments' => $attachments,
            'reactions' => $reactions,
            'is_edited' => (bool) ($m->is_edited ?? false),
            'is_deleted' => $isDeleted,
            'delivered_at' => $m->delivered_at?->toIso8601String(),
            'read_at' => $m->read_at?->toIso8601String(),
            'created_at' => $m->created_at?->toIso8601String(),
            'updated_at' => $m->updated_at?->toIso8601String(),
        ];
    }
}
