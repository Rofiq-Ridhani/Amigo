<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Services\MessageActionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Edit, hapus, dan reaksi pesan (kontrak §D.5).
 * Urutan pemeriksaan: bukan peserta -> 404, bukan pengirim -> 403, state -> 409, input -> 422.
 */
class MessageActionController extends Controller
{
    public function __construct(private readonly MessageActionService $actions) {}

    /** PATCH /api/v1/messages/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $message = $this->findForParticipant($request, $id);

        if ((int) $message->sender_id !== $request->user()->id) {
            return ApiResponse::error('FORBIDDEN', 'Hanya pengirim yang boleh mengedit pesan.', 403);
        }

        if ($message->trashed()) {
            return ApiResponse::error('MESSAGE_DELETED', 'Pesan sudah dihapus.', 409);
        }

        $minutes = (int) config('chat.edit_window_minutes');
        if ($message->created_at->lt(now()->subMinutes($minutes))) {
            return ApiResponse::error('EDIT_WINDOW_EXPIRED', "Pesan hanya bisa diedit dalam $minutes menit.", 409);
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:'.config('chat.message_max_length')],
        ]);

        [$fresh] = $this->actions->edit($message, $data['body']);

        return ApiResponse::success(['message' => $fresh->toApiArray()], 'Pesan diedit');
    }

    /** DELETE /api/v1/messages/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $message = $this->findForParticipant($request, $id);

        if ((int) $message->sender_id !== $request->user()->id) {
            return ApiResponse::error('FORBIDDEN', 'Hanya pengirim yang boleh menghapus pesan.', 403);
        }

        $deleted = $this->actions->delete($message);

        return ApiResponse::success(['message' => $deleted->toApiArray()], 'Pesan dihapus');
    }

    /** PUT /api/v1/messages/{id}/reaction */
    public function react(Request $request, int $id): JsonResponse
    {
        $message = $this->findForParticipant($request, $id);

        if ($message->trashed()) {
            return ApiResponse::error('MESSAGE_DELETED', 'Pesan sudah dihapus.', 409);
        }

        $data = $request->validate([
            'emoji' => ['required', 'string', Rule::in(config('chat.reaction_emojis'))],
        ]);

        [$fresh] = $this->actions->react($message, $request->user(), $data['emoji']);

        return $this->reactionResponse($fresh);
    }

    /** DELETE /api/v1/messages/{id}/reaction (idempoten) */
    public function unreact(Request $request, int $id): JsonResponse
    {
        $message = $this->findForParticipant($request, $id);

        [$fresh] = $this->actions->unreact($message, $request->user());

        return $this->reactionResponse($fresh);
    }

    private function reactionResponse(Message $message): JsonResponse
    {
        return ApiResponse::success([
            'message_id' => $message->id,
            'revision' => $message->revision,
            'reactions' => $message->reactionsForApi(),
        ]);
    }

    /** Bukan peserta percakapan -> 404 (anti-IDOR, kontrak §D.0). */
    private function findForParticipant(Request $request, int $id): Message
    {
        $me = $request->user()->id;

        return Message::withTrashed()
            ->whereHas('conversation', fn ($q) => $q->forUser($me))
            ->with(['replyTo.attachments', 'reactions', 'attachments'])
            ->findOrFail($id);
    }
}
