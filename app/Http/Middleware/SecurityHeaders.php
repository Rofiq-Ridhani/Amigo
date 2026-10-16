<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keamanan untuk semua respons web/API (kontrak §I no. 8).
 *
 * CSP dikendalikan CHAT_CSP_MODE: off | report-only (default) | enforce.
 * Default report-only: pelanggaran hanya muncul di Console browser, tidak memblokir apa pun,
 * sehingga Frontend bisa merapikannya sebelum diubah ke `enforce`.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), geolocation=(), microphone=(self)');

        // Respons yang sudah membawa CSP sendiri (mis. lampiran) tidak ditimpa
        $mode = (string) config('chat.csp.mode');
        if ($mode !== 'off' && ! $headers->has('Content-Security-Policy') && ! $headers->has('Content-Security-Policy-Report-Only')) {
            $headers->set(
                $mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only',
                $this->policy(),
            );
        }

        if ($request->isSecure() && app()->isProduction()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function policy(): string
    {
        $socket = rtrim((string) config('chat.socket_url'), '/');
        $socketWs = preg_replace('/^http/', 'ws', $socket); // http->ws, https->wss

        // Saat development, Vite menyajikan skrip/HMR dari port lain
        $dev = [];
        if (app()->environment('local')) {
            $vite = rtrim((string) config('chat.csp.vite_dev_url'), '/');
            $dev = [$vite, preg_replace('/^http/', 'ws', $vite)];
        }

        $directives = [
            "default-src 'self'",
            // 'unsafe-inline' diperlukan selama window.AMIGO ditulis sebagai skrip inline (kontrak §B.3).
            // TODO (hardening lanjutan): ganti dengan nonce per-request.
            "script-src 'self' 'unsafe-inline' ".($dev[0] ?? ''),
            "style-src 'self' 'unsafe-inline' ".($dev[0] ?? ''),
            "img-src 'self' data: blob:",
            "media-src 'self' blob:",
            "font-src 'self' data: ".($dev[0] ?? ''),
            "connect-src 'self' {$socket} {$socketWs} ".implode(' ', $dev),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];

        return implode('; ', array_map('trim', $directives));
    }
}
