<x-app-layout>
    <div class="flex flex-col h-[calc(100vh-4rem)] max-w-3xl mx-auto border-x border-white/10">
        <!-- Chat header -->
        <div class="bg-white/[0.03] px-4 sm:px-6 py-3 border-b border-white/10 flex items-center gap-3">
            <a href="{{ route('users.index') }}" class="text-slate-400 hover:text-white transition shrink-0" aria-label="Back">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
            </a>
            <div class="shrink-0 w-10 h-10 rounded-full bg-gradient-to-br from-brand-500 to-brand-700 flex items-center justify-center text-white font-semibold select-none">
                {{ strtoupper(substr($user->name, 0, 1)) }}
            </div>
            <div class="min-w-0">
                <h2 class="font-semibold text-white truncate leading-tight">{{ $user->name }}</h2>
                <p class="text-xs text-slate-400 truncate">{{ $user->email }}</p>
            </div>
        </div>

        <!-- Messages -->
        <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-4 space-y-3" id="messages-container">
            @forelse ($messages as $message)
                @if ($message->sender_id === Auth::id())
                    <div class="flex justify-end">
                        <div class="max-w-[75%]">
                            <div class="bg-brand-600 text-white rounded-2xl rounded-br-md px-4 py-2.5 shadow-md shadow-brand-900/50">
                                <p class="text-sm break-words whitespace-pre-wrap">{{ $message->message }}</p>
                            </div>
                            <p class="text-xs text-slate-500 mt-1 text-right">
                                {{ $message->created_at->format('H:i') }}
                            </p>
                        </div>
                    </div>
                @else
                    <div class="flex justify-start">
                        <div class="max-w-[75%]">
                            <div class="bg-white/[0.06] text-slate-200 border border-white/10 rounded-2xl rounded-bl-md px-4 py-2.5">
                                <p class="text-sm break-words whitespace-pre-wrap">{{ $message->message }}</p>
                            </div>
                            <p class="text-xs text-slate-500 mt-1">
                                {{ $message->created_at->format('H:i') }}
                            </p>
                        </div>
                    </div>
                @endif
            @empty
                <div class="text-center py-12">
                    <p class="text-slate-400">No messages yet. Say hello!</p>
                </div>
            @endforelse
        </div>

        <!-- Input -->
        <div class="bg-white/[0.03] px-4 sm:px-6 py-3 border-t border-white/10">
            <form id="chat-form" class="flex gap-2">
                @csrf
                <input
                    type="text" id="message-input" name="message" placeholder="Type a message" required maxlength="1000"
                    class="flex-1 border border-white/10 bg-white/5 text-white placeholder-slate-500 focus:border-brand-500 focus:ring-brand-500/50 rounded-full shadow-sm transition px-5 py-2.5"
                    autofocus autocomplete="off"
                />
                <button type="submit" id="send-button" class="shrink-0 inline-flex items-center justify-center w-11 h-11 bg-brand-600 hover:bg-brand-500 rounded-full text-white transition shadow-lg shadow-brand-600/30 disabled:opacity-50" aria-label="Send">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                    </svg>
                </button>
            </form>
            <div id="send-error" class="hidden mt-2 text-xs text-red-400"></div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.getElementById('messages-container');
            const form = document.getElementById('chat-form');
            const input = document.getElementById('message-input');
            const sendBtn = document.getElementById('send-button');
            const errorBox = document.getElementById('send-error');
            const csrfToken = document.querySelector('input[name="_token"]').value;
            const userId = {{ Auth::id() }};
            const partnerId = {{ $user->id }};
            const storeUrl = '{{ route("chat.store", $user) }}';

            if (container) {
                container.scrollTop = container.scrollHeight;
            }

            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            function appendBubble(id, text, time, isMine) {
                const emptyState = container.querySelector('div:only-child');
                if (emptyState && emptyState.textContent.includes('No messages')) {
                    emptyState.remove();
                }

                const wrapper = document.createElement('div');
                wrapper.className = `flex ${isMine ? 'justify-end' : 'justify-start'}`;
                wrapper.setAttribute('data-message-id', id);

                if (isMine) {
                    wrapper.innerHTML = `
                        <div class="max-w-[75%]">
                            <div class="bg-brand-600 text-white rounded-2xl rounded-br-md px-4 py-2.5 shadow-md shadow-brand-900/50">
                                <p class="text-sm break-words whitespace-pre-wrap">${escapeHtml(text)}</p>
                            </div>
                            <p class="text-xs text-slate-500 mt-1 text-right">${time}</p>
                        </div>
                    `;
                } else {
                    wrapper.innerHTML = `
                        <div class="max-w-[75%]">
                            <div class="bg-white/[0.06] text-slate-200 border border-white/10 rounded-2xl rounded-bl-md px-4 py-2.5">
                                <p class="text-sm break-words whitespace-pre-wrap">${escapeHtml(text)}</p>
                            </div>
                            <p class="text-xs text-slate-500 mt-1">${time}</p>
                        </div>
                    `;
                }

                container.appendChild(wrapper);
                container.scrollTop = container.scrollHeight;
            }

            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const text = input.value.trim();
                if (!text) return;

                sendBtn.disabled = true;
                errorBox.classList.add('hidden');

                try {
                    const res = await fetch(storeUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({ message: text }),
                    });

                    const data = await res.json();

                    if (!res.ok || !data.success) {
                        throw new Error(data.message || 'Failed to send');
                    }

                    appendBubble(data.message.id, data.message.message, data.message.created_at, true);
                    input.value = '';
                    input.focus();
                } catch (err) {
                    errorBox.textContent = err.message || 'Network error';
                    errorBox.classList.remove('hidden');
                } finally {
                    sendBtn.disabled = false;
                }
            });

            // Listen for incoming messages via WebSocket
            if (window.Echo) {
                window.Echo.private(`chat.${userId}`)
                    .listen('.message-sent', (e) => {
                        if (parseInt(e.sender_id) === partnerId) {
                            appendBubble(e.id, e.message, e.created_at, false);
                        }
                    });
            }
        });
    </script>
</x-app-layout>
