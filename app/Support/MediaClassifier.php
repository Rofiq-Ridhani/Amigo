<?php

namespace App\Support;

/**
 * Menentukan jenis lampiran dari ISI file (kontrak §G): MIME dideteksi server via finfo,
 * BUKAN dari ekstensi atau Content-Type kiriman client. Hanya jenis di allow-list yang diterima.
 * Kelas ini sengaja tidak bergantung pada Laravel agar mudah diuji.
 */
final class MediaClassifier
{
    /** mime => [kind, ekstensi penyimpanan] — ekstensi file di disk selalu dari sini, bukan dari client. */
    public const ALLOWED = [
        'image/jpeg' => ['image', 'jpg'],
        'image/png' => ['image', 'png'],
        'image/gif' => ['image', 'gif'],
        'image/webp' => ['image', 'webp'],

        'video/mp4' => ['video', 'mp4'],
        'video/webm' => ['video', 'webm'],
        'video/quicktime' => ['video', 'mov'],

        'audio/mpeg' => ['audio', 'mp3'],
        'audio/ogg' => ['audio', 'ogg'],
        'audio/wav' => ['audio', 'wav'],
        'audio/mp4' => ['audio', 'm4a'],
        'audio/aac' => ['audio', 'aac'],
        'audio/webm' => ['audio', 'weba'],

        'application/pdf' => ['file', 'pdf'],
        'text/plain' => ['file', 'txt'],
        'text/csv' => ['file', 'csv'],
        'application/msword' => ['file', 'doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['file', 'docx'],
        'application/vnd.ms-excel' => ['file', 'xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['file', 'xlsx'],
        'application/vnd.ms-powerpoint' => ['file', 'ppt'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['file', 'pptx'],
        'application/zip' => ['file', 'zip'],
    ];

    /** Nama MIME alternatif yang dikembalikan berbagai versi libmagic. */
    private const ALIASES = [
        'image/jpg' => 'image/jpeg',
        'audio/x-wav' => 'audio/wav',
        'audio/wave' => 'audio/wav',
        'audio/vnd.wave' => 'audio/wav',
        'audio/x-m4a' => 'audio/mp4',
        'audio/m4a' => 'audio/mp4',
        'audio/x-aac' => 'audio/aac',
        'audio/x-hx-aac-adts' => 'audio/aac',
        'audio/aacp' => 'audio/aac',
        'audio/x-ogg' => 'audio/ogg',
        'audio/vorbis' => 'audio/ogg',
        'audio/mp3' => 'audio/mpeg',
        'audio/x-mp3' => 'audio/mpeg',
        'audio/x-mpeg' => 'audio/mpeg',
        'application/ogg' => 'audio/ogg',
        'application/x-zip' => 'application/zip',
        'application/x-zip-compressed' => 'application/zip',
        'application/csv' => 'text/csv',
    ];

    /** Dokumen Office lama (OLE) terdeteksi dengan nama yang berbeda-beda. */
    private const OLE_TYPES = [
        'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint',
        'application/vnd.ms-office', 'application/x-ole-storage', 'application/cdfv2', 'application/cdfv2-corrupt',
    ];

    private const OFFICE_BY_EXT = [
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    private const OLE_BY_EXT = [
        'doc' => 'application/msword',
        'xls' => 'application/vnd.ms-excel',
        'ppt' => 'application/vnd.ms-powerpoint',
    ];

    /**
     * @return array{mime:string,kind:string,ext:string}|null null = jenis tidak diizinkan
     */
    public static function classify(string $path, string $clientName = '', ?string $clientMime = null): ?array
    {
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($detected === false) {
            return null;
        }

        $detected = strtolower($detected);
        $mime = self::ALIASES[$detected] ?? $detected;
        $ext = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
        $clientMime = strtolower((string) $clientMime);

        if (in_array($mime, self::OLE_TYPES, true)) {
            // OLE biasa membawa .doc/.xls/.ppt; selain itu tolak
            $mime = self::OLE_BY_EXT[$ext] ?? '';
        } elseif ($mime === 'application/zip' && isset(self::OFFICE_BY_EXT[$ext])) {
            $mime = self::OFFICE_BY_EXT[$ext]; // docx/xlsx/pptx adalah arsip zip
        } elseif ($mime === 'video/mp4' && $ext === 'm4a') {
            $mime = 'audio/mp4';
        } elseif ($mime === 'video/webm' && ($ext === 'weba' || $clientMime === 'audio/webm')) {
            // finfo tidak bisa membedakan audio-saja dari video; rekaman suara browser = audio/webm
            $mime = 'audio/webm';
        } elseif ($mime === 'text/plain' && $ext === 'csv') {
            $mime = 'text/csv';
        }

        if (! isset(self::ALLOWED[$mime])) {
            return null;
        }

        [$kind, $storedExt] = self::ALLOWED[$mime];

        return ['mime' => $mime, 'kind' => $kind, 'ext' => $storedExt];
    }
}
