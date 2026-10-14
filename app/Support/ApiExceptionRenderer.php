<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Mengubah semua error pada /api/* dan /internal/* (atau request yang meminta JSON)
 * menjadi format kontrak §F. Mengembalikan null bila request bukan JSON,
 * sehingga halaman Blade tetap memakai perilaku bawaan Laravel (redirect, dll).
 */
class ApiExceptionRenderer
{
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        $wantsJson = $request->is('api/*') || $request->is('internal/*') || $request->expectsJson();

        if (! $wantsJson) {
            return null;
        }

        if ($e instanceof ValidationException) {
            return ApiResponse::error('VALIDATION_ERROR', 'Data yang dikirim tidak valid.', 422, $e->errors());
        }

        if ($e instanceof AuthenticationException) {
            return ApiResponse::error('UNAUTHENTICATED', 'Silakan login terlebih dahulu.', 401);
        }

        if ($e instanceof AuthorizationException) {
            return ApiResponse::error('FORBIDDEN', 'Anda tidak berhak melakukan aksi ini.', 403);
        }

        if ($e instanceof ModelNotFoundException) {
            return ApiResponse::error('NOT_FOUND', 'Data tidak ditemukan.', 404);
        }

        if ($e instanceof TokenMismatchException) {
            return ApiResponse::error('CSRF_MISMATCH', 'Sesi kedaluwarsa. Muat ulang halaman.', 419);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $headers = $e->getHeaders();

            [$code, $message] = match ($status) {
                401 => ['UNAUTHENTICATED', 'Silakan login terlebih dahulu.'],
                403 => ['FORBIDDEN', 'Anda tidak berhak melakukan aksi ini.'],
                404 => ['NOT_FOUND', 'Data tidak ditemukan.'],
                413 => ['PAYLOAD_TOO_LARGE', 'Ukuran data terlalu besar.'],
                415 => ['UNSUPPORTED_MEDIA_TYPE', 'Tipe file tidak didukung.'],
                419 => ['CSRF_MISMATCH', 'Sesi kedaluwarsa. Muat ulang halaman.'],
                429 => ['RATE_LIMITED', 'Terlalu banyak permintaan. Coba lagi sebentar lagi.'],
                503 => ['SERVICE_UNAVAILABLE', 'Layanan sedang tidak tersedia.'],
                default => ['SERVER_ERROR', 'Terjadi kesalahan pada server.'],
            };

            return ApiResponse::error($code, $message, $status, null, $headers);
        }

        // Error tak terduga: pesan generik (detail hanya di storage/logs/laravel.log)
        return ApiResponse::error('SERVER_ERROR', 'Terjadi kesalahan pada server.', 500);
    }
}
