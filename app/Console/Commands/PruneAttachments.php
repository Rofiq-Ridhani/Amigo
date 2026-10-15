<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Menghapus lampiran yatim: sudah diunggah tetapi tidak pernah dilampirkan ke pesan
 * dalam `chat.orphan_ttl_hours` jam (kontrak §G). Dijadwalkan tiap jam.
 */
class PruneAttachments extends Command
{
    protected $signature = 'attachments:prune {--hours= : Batas umur (jam); default dari config chat.orphan_ttl_hours}';

    protected $description = 'Hapus lampiran pending (tidak pernah dikirim) yang sudah kedaluwarsa';

    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?: config('chat.orphan_ttl_hours'));
        $deleted = 0;

        Attachment::query()
            ->whereNull('message_id')
            ->where('created_at', '<', now()->subHours($hours))
            ->orderBy('id')
            ->chunkById(100, function ($orphans) use (&$deleted) {
                foreach ($orphans as $attachment) {
                    try {
                        Storage::disk($attachment->disk)->delete($attachment->path);
                    } catch (Throwable $e) {
                        Log::warning('Gagal menghapus file yatim', ['path' => $attachment->path, 'error' => $e->getMessage()]);
                    }

                    $attachment->delete();
                    $deleted++;
                }
            });

        $this->info("Lampiran yatim dihapus: {$deleted}");

        return self::SUCCESS;
    }
}
