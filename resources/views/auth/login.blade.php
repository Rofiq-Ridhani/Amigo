{{-- resources/views/auth/login.blade.php --}}
@extends('layouts.app')

@section('title', 'Masuk · Amigo')

@section('content')
<main class="mx-auto flex min-h-dvh w-full max-w-sm flex-col justify-center px-5 py-10">
    <h1 class="text-2xl font-semibold tracking-tight text-brand-700">Amigo</h1>
    <p class="mt-1 text-sm text-slate-600">Masuk untuk mulai mengobrol.</p>

    <form method="POST" action="{{ url('/login') }}" class="mt-8 space-y-4" novalidate>
        @csrf

        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                {{ $errors->first('email') ?: $errors->first() }}
            </div>
        @endif

        <div>
            <label for="email" class="block text-sm font-medium text-slate-700">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email"
                   class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-600/30">
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password"
                   class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-600/30">
        </div>

        <label class="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" name="remember" value="1" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-600">
            Ingat saya
        </label>

        <button type="submit"
                class="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2">
            Masuk
        </button>
    </form>

    <p class="mt-6 text-sm text-slate-600">
        Belum punya akun?
        <a href="{{ url('/register') }}" class="font-medium text-brand-700 underline underline-offset-2">Daftar</a>
    </p>
</main>
@endsection