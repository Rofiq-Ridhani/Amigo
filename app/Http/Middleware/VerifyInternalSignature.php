<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Memverifikasi panggilan Node -> Laravel (kontrak §D.9):
 *   X-Internal-Timestamp : unix time (detik), selisih maksimal 60 detik
 *   X-Internal-Signature : hex(HMAC-SHA256(INTERNAL_API_SECRET, timestamp + "." + rawBody))
 */
class VerifyInternalSignature
{
    private const MAX_SKEW_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('chat.internal_secret');

        if ($secret === '') {
            return ApiResponse::error('SERVICE_UNAVAILABLE', 'Endpoint internal belum dikonfigurasi.', 503);
        }

        $timestamp = (string) $request->header('X-Internal-Timestamp', '');
        $signature = (string) $request->header('X-Internal-Signature', '');

        if (! ctype_digit($timestamp) || $signature === '' || abs(time() - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            return ApiResponse::error('UNAUTHENTICATED', 'Tanda tangan internal tidak valid.', 401);
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            return ApiResponse::error('UNAUTHENTICATED', 'Tanda tangan internal tidak valid.', 401);
        }

        return $next($request);
    }
}
