<?php

namespace App\Realtime;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

/**
 * Menerbitkan event ke Redis Pub/Sub untuk diteruskan server Node (kontrak §E.2).
 *
 * Penting: publish dilakukan SETELAH data tersimpan. Jika Redis gagal, data tetap aman
 * di database dan client akan sinkron lewat REST (kontrak §J).
 */
class RealtimePublisher
{
    /**
     * @param  'messages'|'notifications'  $channelKey
     * @param  array<int,int>  $to  user id penerima (Node mengirim ke room user:{id})
     * @param  array<string,mixed>  $data
     * @param  int|null  $ackUserId  user yang ACK-nya dihitung (untuk status delivered)
     */
    public function publish(string $channelKey, string $event, array $to, array $data, ?int $ackUserId = null): void
    {
        if (! config('chat.realtime_enabled')) {
            return;
        }

        $envelope = [
            'event_id' => (string) Str::uuid(),
            'event' => $event,
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'to' => array_values(array_unique($to)),
            'ack_user_id' => $ackUserId,
            'data' => $data,
        ];

        try {
            Redis::publish(
                (string) config("chat.channels.$channelKey"),
                json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
        } catch (Throwable $e) {
            // Jangan gagalkan request: pesan sudah tersimpan (kontrak §J).
            Log::error('Gagal publish event ke Redis', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
