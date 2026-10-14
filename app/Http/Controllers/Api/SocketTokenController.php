<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\SocketToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/v1/socket-token (kontrak §D.2). Dipanggil client pada SETIAP (re)connect. */
class SocketTokenController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $issued = SocketToken::issue($request->user()->id);

        return ApiResponse::success([
            'token' => $issued['token'],
            'expires_at' => $issued['expires_at'],
            'socket_url' => config('chat.socket_url'),
        ]);
    }
}
