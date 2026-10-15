{{-- resources/views/auth/register.blade.php --}}
@extends('layouts.app')

@section('title', 'Daftar · Amigo')

@section('content')
<main class="mx-auto flex min-h-dvh w-full max-w-sm flex-col justify-center px-5 py-10">
    <h1 class="text-2xl font-semibold tracking-tight text-brand-700">Buat akun Amigo</h1>
    <p class="mt-1 text-sm text-slate-600">Cukup satu menit.</p>

    <form method="POST" action="{{ url('/register') }}" class="mt-8 space-y-4" novalidate>
        @csrf

        @if ($errors->any())
            <div role="alert" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                <ul class="list-disc space-y-0.5 pl-4">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div>
            <label for="name" class="block text-sm font-medium text-slate-700">Nama</label>
            <input id="name" name="name" type="text" value="{{ old('name') }}" required maxlength="100" autofocus autocomplete="name"
                   class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-600/30">
        </div>

        <div>
            <label for="email" class="block text-sm font-medium text-slate-700">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="255" autocomplete="email"
                   class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-600/30">
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
            <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password"
                   class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-600/30">
            <p class="mt-1 text-xs text-slate-500">Minimal 8 karakter.</p>
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium text-slate-700">Ulangi password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                   class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand-600 focus:outline-none focus:ring-2 focus:ring-brand-600/30">
        </div>

        <button type="submit"
                class="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2">
            Daftar
        </button>
    </form>

    <p class="mt-6 text-sm text-slate-600">
        Sudah punya akun?
        <a href="{{ url('/login') }}" class="font-medium text-brand-700 underline underline-offset-2">Masuk</a>
    </p>
</main>
@endsection