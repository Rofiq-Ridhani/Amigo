<?php

namespace App\Realtime;

use App\Models\OutboxEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

/**
 * Menerbitkan event ke Redis Pub/Sub untuk diteruskan server Node (kontrak §E.2).
 *
 * Penting: publish dilakukan SETELAH data tersimpan. Event "durable" juga dicatat di tabel
 * outbox sebelum dikirim; bila Redis gagal, `outbox:flush` mengirim ulang (kontrak §J).
 * Pengiriman ulang memakai event_id yang sama, sehingga client membuang duplikat.
 */
class RealtimePublisher
{
    /**
     * Event yang HARUS sampai (membawa data baru). Event lain adalah "snapshot angka absolut"
     * (unread:update, notification:read) yang basi bila dikirim terlambat; client memperbaikinya
     * lewat sync:required + polling unread (kontrak §H.6), jadi tidak masuk outbox.
     */
    public const DURABLE_EVENTS = [
        'message:new',
        'message:updated',
        'message:deleted',
        'message:reaction',
        'message:read',
        'notification:new',
        'conversation:updated',
        'conversation:removed',
    ];

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

        $eventId = (string) Str::uuid();
        $channel = (string) config("chat.channels.$channelKey");

        $json = json_encode([
            'event_id' => $eventId,
            'event' => $event,
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'to' => array_values(array_unique($to)),
            'ack_user_id' => $ackUserId,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $outbox = in_array($event, self::DURABLE_EVENTS, true) ? $this->record($eventId, $channel, $json) : null;

        $sent = $this->send($channel, $json);

        if ($outbox !== null) {
            $this->mark($outbox, $sent);
        }
    }

    /** Kirim satu envelope ke Redis. Mengembalikan false bila gagal (tidak pernah melempar). */
    public function send(string $channel, string $json): bool
    {
        try {
            Redis::publish($channel, $json);

            return true;
        } catch (Throwable $e) {
            // Jangan gagalkan request: pesan sudah tersimpan (kontrak §J).
            Log::error('Gagal publish event ke Redis', ['channel' => $channel, 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function record(string $eventId, string $channel, string $json): ?OutboxEvent
    {
        try {
            return OutboxEvent::create([
                'event_id' => $eventId,
                'channel' => $channel,
                'payload' => $json,
                'attempts' => 0,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Gagal mencatat event ke outbox', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function mark(OutboxEvent $outbox, bool $sent): void
    {
        try {
            $outbox->forceFill(['attempts' => 1, 'published_at' => $sent ? now() : null])->save();
        } catch (Throwable $e) {
            Log::error('Gagal memperbarui status outbox', ['error' => $e->getMessage()]);
        }
    }
}
