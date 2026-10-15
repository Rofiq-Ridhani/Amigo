<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Message;
use App\Support\ApiResponse;
use App\Support\MediaClassifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Throwable;

/**
 * Upload, hapus (pending), dan akses lampiran (kontrak §D.6, §G).
 * File disimpan di disk privat dengan nama acak dan HANYA bisa diakses lewat controller ini.
 */
class AttachmentController extends Controller
{
    private const MAX_PIXELS = 100_000_000;

    /** POST /api/v1/attachments (multipart/form-data, field `file`, satu file per request) */
    public function store(Request $request): JsonResponse
    {
        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            // Isi form melebihi post_max_size PHP -> $_FILES kosong
            if ((int) $request->server('CONTENT_LENGTH') > $this->iniBytes('post_max_size')) {
                return ApiResponse::error('PAYLOAD_TOO_LARGE', 'Ukuran file melebihi batas server.', 413);
            }

            throw ValidationException::withMessages(['file' => 'File wajib diunggah.']);
        }

        if (! $file->isValid()) {
            if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                return ApiResponse::error('PAYLOAD_TOO_LARGE', 'Ukuran file melebihi batas server.', 413);
            }

            throw ValidationException::withMessages(['file' => 'Upload gagal. Silakan coba lagi.']);
        }

        $size = (int) $file->getSize();
        $limitsMb = (array) config('chat.limits.max_size_mb');

        if ($size <= 0) {
            throw ValidationException::withMessages(['file' => 'File kosong.']);
        }
        if ($size > max($limitsMb) * 1024 * 1024) {
            return ApiResponse::error('PAYLOAD_TOO_LARGE', 'Ukuran file melebihi batas.', 413);
        }

        // Jenis ditentukan dari ISI file, bukan nama/ekstensi/Content-Type kiriman client
        $type = MediaClassifier::classify($file->getRealPath(), $file->getClientOriginalName(), $file->getClientMimeType());
        if ($type === null) {
            return ApiResponse::error('UNSUPPORTED_MEDIA_TYPE', 'Jenis file tidak diizinkan.', 415);
        }

        $maxMb = (int) ($limitsMb[$type['kind']] ?? 0);
        if ($size > $maxMb * 1024 * 1024) {
            return ApiResponse::error('PAYLOAD_TOO_LARGE', "Ukuran maksimal untuk {$type['kind']} adalah {$maxMb} MB.", 413);
        }

        $width = $height = null;
        if ($type['kind'] === 'image') {
            $dimensions = @getimagesize($file->getRealPath());
            if ($dimensions === false) {
                return ApiResponse::error('UNSUPPORTED_MEDIA_TYPE', 'Gambar tidak valid.', 415);
            }
            [$width, $height] = $dimensions;
            if ($width * $height > self::MAX_PIXELS) {
                throw ValidationException::withMessages(['file' => 'Dimensi gambar terlalu besar.']);
            }
        }

        $disk = (string) config('chat.media_disk');
        $stored = Storage::disk($disk)->putFileAs(
            'attachments/'.now()->format('Y/m'),
            $file,
            Str::uuid()->toString().'.'.$type['ext'], // ekstensi dari hasil deteksi, bukan dari client
        );

        if ($stored === false) {
            Log::error('Gagal menyimpan lampiran ke disk', ['disk' => $disk]);

            return ApiResponse::error('SERVER_ERROR', 'File gagal disimpan.', 500);
        }

        $attachment = Attachment::create([
            'message_id' => null,
            'uploader_id' => $request->user()->id,
            'disk' => $disk,
            'path' => $stored,
            'original_name' => $this->sanitizeName($file->getClientOriginalName(), $type['ext']),
            'mime_type' => $type['mime'],
            'kind' => $type['kind'],
            'size_bytes' => $size,
            'width' => $width,
            'height' => $height,
        ]);

        return ApiResponse::success(['attachment' => $attachment->toApiArray()], 'File diunggah', 201);
    }

    /** DELETE /api/v1/attachments/{id} — hanya milik sendiri dan yang masih pending */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $attachment = Attachment::where('uploader_id', $request->user()->id)->findOrFail($id);

        if ($attachment->message_id !== null) {
            return ApiResponse::error('ATTACHMENT_ALREADY_ATTACHED', 'Lampiran sudah dipakai pada pesan.', 409);
        }

        try {
            Storage::disk($attachment->disk)->delete($attachment->path);
        } catch (Throwable $e) {
            Log::warning('Gagal menghapus file lampiran', ['path' => $attachment->path, 'error' => $e->getMessage()]);
        }

        $attachment->delete();

        return ApiResponse::success(null, 'Lampiran dihapus');
    }

    /** GET /api/v1/attachments/{id}/content[?download=1] — stream file; mendukung header Range */
    public function content(Request $request, int $id): BinaryFileResponse
    {
        $attachment = Attachment::findOrFail($id);
        $me = $request->user()->id;

        if ($attachment->message_id === null) {
            $allowed = (int) $attachment->uploader_id === $me; // pending: hanya pengunggah
        } else {
            // Peserta percakapan, dan pesan belum dihapus (pesan terhapus tidak lagi punya akses)
            $allowed = Message::whereKey($attachment->message_id)
                ->whereHas('conversation', fn ($q) => $q->forUser($me))
                ->exists();
        }

        // 404 (bukan 403) agar keberadaan file tidak bocor — kontrak §D.0
        abort_unless($allowed, 404);

        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        // Hanya gambar/video/audio yang boleh tampil inline; selain itu SELALU unduhan
        $inline = in_array($attachment->kind, ['image', 'video', 'audio'], true) && ! $request->boolean('download');

        $response = response()->file($disk->path($attachment->path), [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);

        // BinaryFileResponse secara bawaan bersifat `public`; file ini PRIVAT (hanya untuk peserta),
        // jadi tidak boleh disimpan oleh cache bersama (proxy/CDN). Hanya cache browser pengguna.
        $response->setPrivate();
        $response->setMaxAge(3600);;

        $response->setContentDisposition(
            $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $attachment->original_name,
            $this->asciiFallback($attachment->original_name),
        );

        return $response;
    }

    /** Nama file untuk tampilan: tanpa path/karakter kontrol, maksimal 255 karakter. */
    private function sanitizeName(string $name, string $fallbackExt): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        $name = trim(mb_substr($name, 0, 255));

        return $name === '' || $name === '.' || $name === '..' ? 'file.'.$fallbackExt : $name;
    }

    private function asciiFallback(string $name): string
    {
        $ascii = str_replace(['%', '/', '\\', '"'], '_', Str::ascii($name));

        return $ascii === '' ? 'file' : $ascii;
    }

    private function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));
        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
