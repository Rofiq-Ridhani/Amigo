<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Format response final proyek Amigo — kontrak §F.
 * Semua endpoint /api/* dan /internal/* WAJIB memakai kelas ini.
 */
class ApiResponse
{
    /**
     * @param  array<string,mixed>|null  $data  selalu objek (atau null), bukan array di root
     * @param  array<string,mixed>  $meta
     */
    public static function success(?array $data = [], string $message = 'OK', int $status = 200, array $meta = []): JsonResponse
    {
        $body = [
            'success' => true,
            'data' => $data === null ? null : (object) $data,
            'message' => $message,
        ];

        if ($meta !== []) {
            $body['meta'] = (object) $meta;
        }

        return response()->json($body, $status);
    }

    /**
     * @param  array<string,array<int,string>>|null  $details  hanya untuk VALIDATION_ERROR
     * @param  array<string,string>  $headers
     */
    public static function error(string $code, string $message, int $status, ?array $details = null, array $headers = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => [
                'code' => $code,
                'details' => $details,
            ],
        ], $status, $headers);
    }
}
