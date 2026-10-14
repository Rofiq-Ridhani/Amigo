{{-- resources/views/chat/index.blade.php --}}
@extends('layouts.app')

@section('title', 'Obrolan · Amigo')

@section('content')
<div id="chat-root" data-open="false" class="group flex h-dvh">
    {{-- Sidebar (di ponsel disembunyikan saat percakapan terbuka) --}}
    <aside class="flex w-full flex-col border-r border-slate-200 bg-white group-data-[open=true]:hidden md:w-96 md:group-data-[open=true]:flex">
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

    {{-- Area percakapan --}}
    <main id="chat-pane" class="hidden min-w-0 flex-1 flex-col bg-slate-50 group-data-[open=true]:flex md:flex">
        <div id="empty-state" class="m-auto max-w-xs p-8 text-center">
            <h2 class="text-lg font-semibold text-slate-800">Belum ada percakapan dibuka</h2>
            <p class="mt-1 text-sm text-slate-500">Pilih pengguna di samping untuk mulai mengobrol.</p>
        </div>

        <section id="conversation-view" hidden class="flex h-full min-h-0 flex-col">
            <header class="flex items-center gap-3 border-b border-slate-200 bg-white px-3 py-3">
                <button id="back-button" type="button" aria-label="Kembali ke daftar pengguna"
                        class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 md:hidden">
                    <svg viewBox="0 0 20 20" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.5 4.5 7 10l5.5 5.5"/></svg>
                </button>
                <span id="peer-avatar" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700"></span>
                <div class="min-w-0">
                    <p id="peer-name" class="truncate text-sm font-semibold text-slate-900"></p>
                    <p id="peer-status" class="truncate text-xs text-slate-500"></p>
                </div>
            </header>

            <div id="message-scroll" class="flex-1 overflow-y-auto px-4 py-3">
                <div class="pb-3 text-center">
                    <button id="load-older" type="button" hidden
                            class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                        Muat pesan sebelumnya
                    </button>
                </div>
                <ul id="message-list" class="space-y-2"></ul>
                <div class="py-6 text-center">
                    <p id="msg-state" class="text-sm text-slate-500" aria-live="polite"></p>
                    <button id="msg-retry" type="button" hidden
                            class="mt-2 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600">
                        Coba lagi
                    </button>
                </div>
            </div>

            <form id="composer" class="flex items-end gap-2 border-t border-slate-200 bg-white p-3">
                <label for="composer-input" class="sr-only">Tulis pesan</label>
                <textarea id="composer-input" rows="1" maxlength="2000" placeholder="Tulis pesan…"
                          class="max-h-32 min-h-10 flex-1 resize-none rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm focus:border-brand-600 focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-600/30"></textarea>
                <button type="submit"
                        class="h-10 rounded-lg bg-brand-600 px-4 text-sm font-medium text-white hover:bg-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 focus-visible:ring-offset-2">
                    Kirim
                </button>
            </form>
        </section>
    </main>
</div>

{{-- Templat baris pengguna; isi diisi lewat textContent di JS --}}
<template id="user-item-template">
    <li>
        <button type="button" data-open
                class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-600">
            <span class="relative shrink-0">
                <span data-avatar class="flex h-10 w-10 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700"></span>
                <span data-dot class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-white bg-slate-300"></span>
            </span>
            <span class="min-w-0">
                <span data-name class="block truncate text-sm font-medium text-slate-900"></span>
                <span data-status class="block truncate text-xs text-slate-500"></span>
            </span>
        </button>
    </li>
</template>
@endsection