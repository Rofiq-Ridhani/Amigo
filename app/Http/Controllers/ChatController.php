<?php

namespace App\Http\Controllers;

use App\Events\MessageSent;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ChatController extends Controller
{
    /**
     * Show list of other users to chat with.
     */
    public function index(): View
    {
        $users = User::where('id', '!=', Auth::id())->orderBy('name')->get();

        // Get last message for each user pair
        $lastMessages = [];
        foreach ($users as $user) {
            $msg = Message::where(function ($q) use ($user) {
                $q->where('sender_id', Auth::id())->where('recipient_id', $user->id);
            })->orWhere(function ($q) use ($user) {
                $q->where('sender_id', $user->id)->where('recipient_id', Auth::id());
            })->latest()->first();

            if ($msg) {
                $unreadCount = Message::where('sender_id', $user->id)
                    ->where('recipient_id', Auth::id())
                    ->whereNull('read_at')
                    ->count();
                $lastMessages[$user->id] = [
                    'message' => $msg,
                    'unread' => $unreadCount,
                ];
            }
        }

        return view('users.index', compact('users', 'lastMessages'));
    }

    /**
     * Show chat conversation with a specific user.
     */
    public function show(User $user): View
    {
        if ($user->id === Auth::id()) {
            abort(403, 'You cannot chat with yourself.');
        }

        $messages = Message::getConversation(Auth::id(), $user->id);

        // Mark messages from this user as read
        Message::where('sender_id', $user->id)
            ->where('recipient_id', Auth::id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return view('chat.show', compact('user', 'messages'));
    }

    /**
     * Send a message to a specific user.
     */
    public function store(Request $request, User $user): JsonResponse|RedirectResponse
    {
        if ($user->id === Auth::id()) {
            abort(403, 'You cannot send a message to yourself.');
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
        ]);

        $data = app(\App\Services\MessageService::class)->send(
            Auth::id(),
            $user->id,
            $validated['message']
        );

        // Return JSON for AJAX, redirect for non-AJAX fallback
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $data,
            ], 201);
        }

        return redirect()->route('chat.show', $user);
    }
}
