<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\ReadReceiptService;
use App\Services\UnreadService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Status baca & unread (kontrak §D.7). */
class ReadStateController extends Controller
{
    public function __construct(
        private readonly ReadReceiptService $receipts,
        private readonly UnreadService $unread,
    ) {}

    /** POST /api/v1/conversations/{id}/read */
    public function markRead(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        // Bukan peserta -> 404 (anti-IDOR, kontrak §D.0)
        $conversation = Conversation::forUser($user->id)->findOrFail($id);

        $data = $request->validate([
            'up_to_message_id' => [
                'required',
                'integer',
                'min:1',
                Rule::exists('messages', 'id')->where('conversation_id', $conversation->id),
            ],
        ]);

        return ApiResponse::success(
            $this->receipts->markRead($conversation, $user, (int) $data['up_to_message_id']),
        );
    }

    /** GET /api/v1/unread-counts */
    public function counts(Request $request): JsonResponse
    {
        $me = $request->user()->id;

        return ApiResponse::success([
            'total' => $this->unread->total($me),
            'by_conversation' => $this->unread->byConversation($me),
        ]);
    }
}
