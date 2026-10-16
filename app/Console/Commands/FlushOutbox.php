<?php

namespace App\Console\Commands;

use App\Models\OutboxEvent;
use App\Realtime\RealtimePublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Mengirim ulang event yang gagal dipublish ke Redis (kontrak §J). Dijadwalkan tiap menit.
 * - Berhenti di kegagalan pertama (Redis masih mati: tidak perlu menghujani).
 * - Event yang terlalu lama tidak terkirim dibuang; client pulih lewat resync (kontrak §H.6).
 * - Event terkirim dibersihkan setelah 24 jam.
 */
class FlushOutbox extends Command
{
    private const GRACE_SECONDS = 5; // beri waktu percobaan pertama yang masih berjalan di request

    protected $signature = 'outbox:flush {--limit=200 : Maksimal event yang dikirim ulang per eksekusi}';

    protected $description = 'Kirim ulang event realtime yang gagal dipublish ke Redis';

    public function handle(RealtimePublisher $publisher): int
    {
        $maxAge = (int) config('chat.outbox_max_age_minutes');

        $expired = OutboxEvent::whereNull('published_at')
            ->where('created_at', '<', now()->subMinutes($maxAge))
            ->delete();

        if ($expired > 0) {
            Log::warning('Event outbox kedaluwarsa dibuang (client akan resync)', ['jumlah' => $expired]);
        }

        $rows = OutboxEvent::whereNull('published_at')
            ->where('created_at', '<=', now()->subSeconds(self::GRACE_SECONDS))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        $sent = 0;

        foreach ($rows as $row) {
            if ($publisher->send($row->channel, $row->payload)) {
                $row->forceFill(['published_at' => now(), 'attempts' => $row->attempts + 1])->save();
                $sent++;

                continue;
            }

            $row->forceFill(['attempts' => $row->attempts + 1])->save();
            $this->warn('Redis belum bisa dihubungi; sisa event dicoba lagi pada eksekusi berikutnya.');
            break;
        }

        $pruned = OutboxEvent::whereNotNull('published_at')
            ->where('published_at', '<', now()->subDay())
            ->delete();

        $this->info("Outbox: terkirim ulang {$sent}, kedaluwarsa dibuang {$expired}, dibersihkan {$pruned}");

        return self::SUCCESS;
    }
}
