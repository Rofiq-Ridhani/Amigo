<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Support\ApiResponse;
use App\Support\Iso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POST /internal/v1/messages/delivered (kontrak §D.9).
 * Node memanggil ini setelah client penerima mengirim ACK. Idempoten.
 */
class DeliveredController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipient_id' => ['required', 'integer', 'exists:users,id'],
            'message_ids' => ['required', 'array', 'min:1', 'max:100'],
            'message_ids.*' => ['integer', 'min:1'],
        ]);

        $now = now();

        $rows = DB::transaction(function () use ($data, $now) {
            // Hanya pesan milik penerima ini yang belum delivered
            $rows = Message::query()
                ->whereIn('id', $data['message_ids'])
                ->where('recipient_id', $data['recipient_id'])
                ->whereNull('delivered_at')
                ->get(['id', 'conversation_id', 'sender_id']);

            if ($rows->isNotEmpty()) {
                Message::whereIn('id', $rows->pluck('id'))->update([
                    'delivered_at' => $now,
                    'updated_at' => $now,
                    'revision' => DB::raw('revision + 1'),
                ]);
            }

            return $rows;
        });

        return ApiResponse::success([
            'delivered' => $rows->map(fn (Message $m) => [
                'id' => $m->id,
                'conversation_id' => $m->conversation_id,
                'sender_id' => $m->sender_id,
                'delivered_at' => Iso::format($now),
            ])->values()->all(),
        ]);
    }
}
