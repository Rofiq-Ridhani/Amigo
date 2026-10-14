<?php

namespace App\Support;

use RuntimeException;

/**
 * Token koneksi Socket.IO: JWT HS256 sederhana (kontrak §D.2 & §E.1).
 * Klaim: sub (user id), iat, exp, jti, aud = "amigo-socket".
 * Diverifikasi oleh server Node memakai SOCKET_SECRET yang sama.
 */
class SocketToken
{
    /**
     * @return array{token:string,expires_at:string}
     */
    public static function issue(int $userId): array
    {
        $secret = (string) config('chat.socket_secret');

        if ($secret === '') {
            throw new RuntimeException('SOCKET_SECRET belum diisi di .env');
        }

        $now = time();
        $exp = $now + (int) config('chat.socket_token_ttl');

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'sub' => $userId,
            'iat' => $now,
            'exp' => $exp,
            'jti' => bin2hex(random_bytes(16)),
            'aud' => 'amigo-socket',
        ];

        $unsigned = self::b64(json_encode($header)).'.'.self::b64(json_encode($payload));
        $signature = self::b64(hash_hmac('sha256', $unsigned, $secret, true));

        return [
            'token' => $unsigned.'.'.$signature,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $exp),
        ];
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
