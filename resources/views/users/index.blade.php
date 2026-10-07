<x-app-layout>
    <div class="flex flex-col h-[calc(100vh-4rem)] max-w-3xl mx-auto border-x border-white/10">
        <!-- Chat list header -->
        <div class="bg-white/[0.03] px-6 py-4 border-b border-white/10 flex items-center justify-between">
            <h1 class="text-xl font-bold text-white">Chats</h1>
            <span class="text-sm text-slate-400">{{ $users->count() }} contacts</span>
        </div>

        <!-- Chat list -->
        <div class="flex-1 overflow-y-auto">
            @if ($users->count() > 0)
                @foreach ($users as $user)
                    @php
                        $last = $lastMessages[$user->id] ?? null;
                        $time = $last
                            ? ($last['message']->created_at->isToday()
                                ? $last['message']->created_at->format('H:i')
                                : $last['message']->created_at->format('d/m'))
                            : null;
                    @endphp
                    <a href="{{ route('chat.show', $user) }}"
                       class="flex items-center gap-4 px-6 py-4 border-b border-white/5 hover:bg-white/[0.03] transition">
                        <!-- Avatar -->
                        <div class="shrink-0 w-12 h-12 rounded-full bg-gradient-to-br from-brand-500 to-brand-700 flex items-center justify-center text-white font-semibold text-lg select-none">
                            {{ strtoupper(substr($user->name, 0, 1)) }}
                        </div>

                        <!-- Name + preview -->
                        <div class="flex-1 min-w-0">
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="font-semibold text-white truncate">{{ $user->name }}</span>
                                @if ($time)
                                    <span class="text-xs {{ ($last['unread'] ?? 0) > 0 ? 'text-brand-300' : 'text-slate-500' }} shrink-0">{{ $time }}</span>
                                @endif
                            </div>
                            <div class="flex items-center justify-between gap-3 mt-1">
                                <span class="text-sm {{ ($last['unread'] ?? 0) > 0 ? 'text-slate-200 font-medium' : 'text-slate-400' }} truncate">
                                    @if ($last)
                                        @if ($last['message']->sender_id === Auth::id())
                                            <span class="text-slate-500">You: </span>
                                        @endif
                                        {{ $last['message']->message }}
                                    @else
                                        Start a conversation...
                                    @endif
                                </span>
                                @if (($last['unread'] ?? 0) > 0)
                                    <span class="shrink-0 inline-flex items-center justify-center min-w-5 h-5 px-1.5 text-xs font-bold text-white bg-brand-600 rounded-full">
                                        {{ $last['unread'] }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </a>
                @endforeach
            @else
                <div class="p-12 text-center">
                    <p class="text-slate-400">No users available for chatting.</p>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
