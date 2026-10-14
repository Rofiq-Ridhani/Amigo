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
