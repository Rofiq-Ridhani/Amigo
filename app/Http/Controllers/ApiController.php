<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class ApiController extends Controller
{
    /**
     * GET /api/me — current user (contract §4.4).
     */
    public function me(Request $request): JsonResponse
    {
        $u = $request->user();
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'avatar' => $u->avatar ?? null,
            ],
        ]);
    }

    /**
     * GET /socket-token — signed JWT for Reverb/Socket auth (contract §4.5).
     * Frontend pakai token ini di Echo auth / Socket.IO handshake.
     * TTL 1 jam. Tanpa dependency baru: pakai firebase/php-jwt jika ada,
     * fallback ke HMAC manual.
     */
    public function socketToken(Request $request): JsonResponse
    {
        $expiresIn = 3600;
        $now = time();
        $payload = [
            'sub' => $request->user()->id,
            'email' => $request->user()->email,
            'iat' => $now,
            'exp' => $now + $expiresIn,
            'iss' => config('app.url'),
        ];

        // Ponytail: key = APP_KEY (base64:...). Upgrade: dedicated SOCKET_SECRET di .env jika butuh rotasi terpisah.
        $rawKey = config('app.key');
        $key = str_starts_with($rawKey, 'base64:') ? base64_decode(substr($rawKey, 7)) : $rawKey;

        if (class_exists(JWT::class)) {
            $token = JWT::encode($payload, $key, 'HS256');
        } else {
            $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
            $body = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
            $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$body", $key, true)), '+/', '-_'), '=');
            $token = "$header.$body.$sig";
        }

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token,
                'expires_in' => $expiresIn,
            ],
        ]);
    }
}
