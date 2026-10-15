<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * GET /internal/v1/conversations/{id}/participants (kontrak §D.9).
 * Dipakai Node untuk mengotorisasi event typing (di-cache Node maksimal 60 detik).
 */
class ParticipantsController extends Controller
{
    public function __invoke(int $id): JsonResponse
    {
        $conversation = Conversation::findOrFail($id);

        return ApiResponse::success([
            'user_ids' => [(int) $conversation->user_one_id, (int) $conversation->user_two_id],
        ]);
    }
}
