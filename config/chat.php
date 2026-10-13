<?php

return [

    // URL Socket.IO yang diakses BROWSER (bukan nama service Docker).
    'socket_url' => env('SOCKET_URL', 'http://localhost:3001'),

    // Rahasia penanda token koneksi socket (sama dengan yang dipakai server Node).
    'socket_secret' => env('SOCKET_SECRET'),
    'socket_token_ttl' => (int) env('SOCKET_TOKEN_TTL_SECONDS', 300),

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
