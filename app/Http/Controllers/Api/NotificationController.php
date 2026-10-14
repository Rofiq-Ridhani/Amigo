<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatNotification;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Daftar & penandaan notifikasi (kontrak §D.8). Hanya milik user yang login. */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** GET /api/v1/notifications?unread=1&page=&per_page= */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unread' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $me = $request->user()->id;

        $paginator = ChatNotification::query()
            ->where('user_id', $me)
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return ApiResponse::success(
            [
                'notifications' => $paginator->getCollection()->map(fn (ChatNotification $n) => $n->toApiArray())->values()->all(),
                'unread_count' => $this->notifications->unreadCount($me),
            ],
            'OK',
            200,
            [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }

    /** POST /api/v1/notifications/{id}/read — milik orang lain / tidak ada -> 404. Idempoten. */
    public function read(Request $request, string $id): JsonResponse
    {
        $me = $request->user()->id;

        $notification = ChatNotification::where('user_id', $me)->whereKey($id)->firstOrFail();

        if ($notification->read_at === null) {
            $now = now();
            $notification->forceFill(['read_at' => $now])->save();

            $this->notifications->publishRead($me, [$notification->id], false);
        }

        return ApiResponse::success([
            'notification' => $notification->toApiArray(),
            'unread_count' => $this->notifications->unreadCount($me),
        ]);
    }

    /** POST /api/v1/notifications/read-all */
    public function readAll(Request $request): JsonResponse
    {
        $me = $request->user()->id;
        $now = now();

        $updated = ChatNotification::where('user_id', $me)
            ->whereNull('read_at')
            ->update(['read_at' => $now, 'updated_at' => $now]);

        if ($updated > 0) {
            $this->notifications->publishRead($me, null, true);
        }

        return ApiResponse::success([
            'updated' => $updated,
            'unread_count' => $this->notifications->unreadCount($me),
        ]);
    }
}
