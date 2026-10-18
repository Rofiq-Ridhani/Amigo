<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * GET /internal/v1/conversations/{id}/participants (kontrak §D.9).
 * Dipakai Node untuk mengotorisasi event typing (di-cache Node maksimal 60 detik).
 * direct: [user_one, user_two]; grup: seluruh anggota AKTIF (2 sampai 50 id).
 */
class ParticipantsController extends Controller
{
    public function __invoke(int $id): JsonResponse
    {
        $conversation = Conversation::findOrFail($id);

        return ApiResponse::success([
            'type' => $conversation->type ?? Conversation::TYPE_DIRECT,
            'user_ids' => $conversation->participantIds(),
        ]);
    }
}
