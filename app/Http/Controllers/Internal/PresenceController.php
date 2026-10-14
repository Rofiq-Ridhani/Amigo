<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * POST /internal/v1/presence (kontrak §D.9).
 * Node memanggil ini saat socket terakhir seorang user terputus -> simpan users.last_seen_at.
 */
class PresenceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'status' => ['required', 'in:online,offline'],
            'at' => ['required', 'date'],
        ]);

        if ($data['status'] === 'offline') {
            User::whereKey($data['user_id'])->update([
                'last_seen_at' => Carbon::parse($data['at'])->setTimezone(config('app.timezone')),
            ]);
        }

        return ApiResponse::success(null, 'OK');
    }
}
