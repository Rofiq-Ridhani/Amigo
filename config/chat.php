<?php

return [

    // URL Socket.IO yang diakses BROWSER (bukan nama service Docker).
    'socket_url' => env('SOCKET_URL', 'http://localhost:3001'),

    // Rahasia penanda token koneksi socket (sama dengan yang dipakai server Node).
    'socket_secret' => env('SOCKET_SECRET'),
    'socket_token_ttl' => (int) env('SOCKET_TOKEN_TTL_SECONDS', 300),

    // Rahasia tanda tangan HMAC untuk /internal/* (Node -> Laravel). BEDA dari socket_secret.
    'internal_secret' => env('INTERNAL_API_SECRET'),

    // Channel Redis (kontrak §E.2). Node memakai pattern "*<nama channel>" sehingga
    // prefix Redis milik Laravel tidak masalah.
    'channels' => [
        'messages' => env('REDIS_CHAT_CHANNEL', 'chat.messages'),
        'notifications' => env('REDIS_NOTIFICATION_CHANNEL', 'chat.notifications'),
    ],

    // false = jangan publish ke Redis (dipakai di tes)
    'realtime_enabled' => (bool) env('CHAT_REALTIME_ENABLED', true),

    // Lampiran (kontrak §G): disk PRIVAT, bukan public
    'media_disk' => env('CHAT_MEDIA_DISK', 'local'),
    // Lampiran yang diunggah tetapi tidak pernah dikirim dihapus setelah sekian jam
    'orphan_ttl_hours' => (int) env('CHAT_ORPHAN_TTL_HOURS', 24),

    // Outbox (kontrak §J): event yang gagal dipublish ke Redis dicoba lagi oleh `outbox:flush`;
    // yang lebih tua dari ini dibuang (client pulih lewat resync, kontrak §H.6).
    'outbox_max_age_minutes' => (int) env('CHAT_OUTBOX_MAX_AGE_MINUTES', 30),

    // Batas waktu koneksi/baca Redis (detik) agar request tidak menggantung bila Redis bermasalah
    'redis_timeout' => (float) env('REDIS_TIMEOUT', 1.5),

    // Content-Security-Policy: off | report-only (default, tidak memblokir) | enforce
    'csp' => [
        'mode' => env('CHAT_CSP_MODE', 'report-only'),
        'vite_dev_url' => env('CHAT_CSP_VITE_URL', 'http://localhost:5173'), // hanya dipakai saat APP_ENV=local
    ],

    // Health check server Socket.IO untuk `php artisan amigo:doctor`
    'socket_health_url' => env('SOCKET_HEALTH_URL'),

    'message_max_length' => (int) env('CHAT_MESSAGE_MAX_LENGTH', 2000),
    'edit_window_minutes' => (int) env('CHAT_EDIT_WINDOW_MINUTES', 15),

    // Allow-list reaksi (kontrak §C.5)
    'reaction_emojis' => ['👍', '❤️', '😂', '😮', '😢', '🙏'],

    // Dikirim ke frontend lewat window.AMIGO.limits (kontrak §B.3 & §G)
    'limits' => [
        'message_max_length' => (int) env('CHAT_MESSAGE_MAX_LENGTH', 2000),
        'attachments_per_message' => 5,
        'max_size_mb' => ['image' => 10, 'video' => 50, 'audio' => 20, 'file' => 20],
    ],

];
