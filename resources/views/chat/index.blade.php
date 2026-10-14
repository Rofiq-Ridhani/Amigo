{{-- resources/views/chat/index.blade.php --}}
@extends('layouts.app')

@section('title', 'Obrolan · Amigo')

@section('content')
<div class="flex h-dvh">
    {{-- Sidebar --}}
    <aside class="flex w-full flex-col border-r border-slate-200 bg-white md:w-96">
        <header class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-slate-900">{{ auth()->user()->name }}</p>
                <p class="flex items-center gap-1.5 text-xs text-slate-500">
                    <span id="conn-dot" class="h-2 w-2 rounded-full bg-slate-300"></span>
                    <span id="conn-status" aria-live="polite">Menyambung…</span>
                    <span id="mock-badge" class="hidden rounded bg-amber-100 px-1.5 py-0.5 text-amber-800">Data tiruan</span>
                </p>
            </div>
            <form id="logout-form" method="POST" action="{{ url('/logout') }}">
                @csrf
                <button type="submit"
                        class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                    Keluar
                </button>
            </form>
        </header>

        <div class="border-b border-slate-200 p-3">
            <label for="user-search" class="sr-only">Cari pengguna</label>
            <input id="user-search" type="search" maxlength="50" autocomplete="off" placeholder="Cari nama (minimal 2 huruf)"
                   class="block w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm focus:border-brand-600 focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-600/30">
        </div>

        <div class="flex-1 overflow-y-auto">
            <ul id="user-list" class="divide-y divide-slate-100"></ul>
            <p id="list-state" class="px-4 py-6 text-center text-sm text-slate-500" aria-live="polite">Memuat pengguna…</p>
            <div class="px-4 pb-4 text-center">
                <button id="load-more" type="button" hidden
                        class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                    Muat lebih banyak
                </button>
            </div>
        </div>
    </aside>

    {{-- Area percakapan (Fase 2) --}}
    <main class="hidden flex-1 items-center justify-center bg-slate-50 p-8 md:flex">
        <div class="max-w-xs text-center">
            <h2 class="text-lg font-semibold text-slate-800">Belum ada percakapan dibuka</h2>
            <p class="mt-1 text-sm text-slate-500">Pilih pengguna di samping untuk mulai mengobrol.</p>
        </div>
    </main>
</div>

{{-- Templat baris pengguna; isi diisi lewat textContent di JS --}}
<template id="user-item-template">
    <li class="flex items-center gap-3 px-4 py-3">
        <div class="relative shrink-0">
            <span data-avatar class="flex h-10 w-10 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700"></span>
            <span data-dot class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-white bg-slate-300"></span>
        </div>
        <div class="min-w-0">
            <p data-name class="truncate text-sm font-medium text-slate-900"></p>
            <p data-status class="truncate text-xs text-slate-500"></p>
        </div>
    </li>
</template>
@endsection