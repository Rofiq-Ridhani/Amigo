{{-- resources/views/layouts/app.blade.php --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Amigo')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @auth
        {{-- Kontrak §B.3. Js::from() dipakai (bukan @json polos) agar nama user berisi "</script>" tidak bisa keluar dari tag. --}}
        @php
            $amigo = [
                'currentUser' => ['id' => auth()->id(), 'name' => auth()->user()->name],
                'apiBase' => '/api/v1',
                'socketUrl' => config('chat.socket_url'),
                'reactionEmojis' => config('chat.reaction_emojis'),
                'limits' => config('chat.limits'),
            ];
        @endphp
        <script>
            window.AMIGO = {{ \Illuminate\Support\Js::from($amigo) }};
        </script>
    @endauth
</head>
<body class="min-h-dvh bg-slate-50 font-sans text-slate-900 antialiased">
    @yield('content')
</body>
</html>