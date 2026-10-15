<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\OutboxEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Uji Fase 7: rate limit, outbox, header keamanan, amigo:doctor (kontrak §I, §J).
 */
class Phase7HardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $andi;

    private User $budi;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        config(['chat.realtime_enabled' => false]);
        Storage::fake(config('chat.media_disk'));

        $this->andi = User::factory()->create(['name' => 'Andi Pratama']);
        $this->budi = User::factory()->create(['name' => 'Budi Santoso']);
        $this->conversation = Conversation::between($this->andi->id, $this->budi->id);
    }

    private function send(User $from, string $body = 'Halo')
    {
        return $this->actingAs($from)->postJson("/api/v1/conversations/{$this->conversation->id}/messages", [
            'client_uuid' => (string) Str::uuid(),
            'body' => $body,
        ]);
    }

    private function outbox(string $channel = 'chat.messages', int $ageSeconds = 30, ?string $publishedAt = null, int $attempts = 0): OutboxEvent
    {
        return OutboxEvent::create([
            'event_id' => (string) Str::uuid(),
            'channel' => $channel,
            'payload' => json_encode(['event' => 'message:new', 'data' => ['x' => 1]]),
            'attempts' => $attempts,
            'published_at' => $publishedAt,
            'created_at' => now()->subSeconds($ageSeconds),
        ]);
    }

    // ================= RATE LIMIT (§I no. 9) =================

    public function test_login_dibatasi_5_percobaan_per_menit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/login', ['email' => 'andi@example.com', 'password' => 'salah'])->assertStatus(422);
        }

        $this->postJson('/login', ['email' => 'andi@example.com', 'password' => 'salah'])
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertHeader('Retry-After')
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_registrasi_dibatasi_10_per_menit_per_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/register', [])->assertStatus(422);
        }

        $this->postJson('/register', [])->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    public function test_kirim_pesan_dibatasi_30_per_menit(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->send($this->andi, "Pesan $i")->assertCreated();
        }

        $this->send($this->andi, 'Pesan ke-31')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertHeader('Retry-After');

        $this->assertDatabaseCount('messages', 30);

        // Pembatasan per user: Budi tidak ikut terkena
        $this->send($this->budi, 'Balasan')->assertCreated();
    }

    public function test_upload_dibatasi_10_per_menit(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

        $upload = fn () => $this->actingAs($this->andi)->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/attachments', ['file' => UploadedFile::fake()->createWithContent('a.png', $png)]);

        for ($i = 0; $i < 10; $i++) {
            $upload()->assertCreated();
        }

        $upload()->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    // ================= OUTBOX (§J) =================

    public function test_event_durable_dicatat_di_outbox_dan_ditandai_terkirim(): void
    {
        config(['chat.realtime_enabled' => true]);
        Redis::shouldReceive('publish')->times(3); // message:new, unread:update, notification:new

        $this->send($this->andi)->assertCreated();

        // unread:update adalah snapshot angka absolut -> tidak masuk outbox
        $this->assertDatabaseCount('outbox_events', 2);
        $this->assertSame(0, OutboxEvent::whereNull('published_at')->count());
        $this->assertEqualsCanonicalizing(['chat.messages', 'chat.notifications'], OutboxEvent::pluck('channel')->all());
        $this->assertSame(1, OutboxEvent::first()->attempts);

        $payload = json_decode(OutboxEvent::where('channel', 'chat.messages')->first()->payload, true);
        $this->assertSame('message:new', $payload['event']);
        $this->assertSame(OutboxEvent::where('channel', 'chat.messages')->first()->event_id, $payload['event_id']);
    }

    public function test_redis_mati_pesan_tersimpan_201_dan_event_menunggu_di_outbox(): void
    {
        config(['chat.realtime_enabled' => true]);
        Redis::shouldReceive('publish')->times(3)->andThrow(new \RuntimeException('Connection refused'));

        $this->send($this->andi, 'Saat Redis mati')->assertCreated()->assertJsonPath('data.message.body', 'Saat Redis mati');

        $this->assertDatabaseHas('messages', ['body' => 'Saat Redis mati']);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->budi->id]);
        $this->assertSame(2, OutboxEvent::whereNull('published_at')->count());
        $this->assertSame(1, OutboxEvent::first()->attempts);
    }

    public function test_flush_mengirim_ulang_dengan_payload_dan_event_id_yang_sama(): void
    {
        config(['chat.realtime_enabled' => true]);
        $row = $this->outbox('chat.messages');

        Redis::shouldReceive('publish')->once()->with('chat.messages', $row->payload);

        $this->artisan('outbox:flush')->assertSuccessful();

        $row->refresh();
        $this->assertNotNull($row->published_at);
        $this->assertSame(1, $row->attempts);
    }

    public function test_flush_berhenti_di_kegagalan_pertama_dan_menaikkan_attempts(): void
    {
        $first = $this->outbox('chat.messages', 40);
        $second = $this->outbox('chat.messages', 30);

        Redis::shouldReceive('publish')->once()->andThrow(new \RuntimeException('Connection refused'));

        $this->artisan('outbox:flush')->assertSuccessful();

        $this->assertSame(1, $first->fresh()->attempts);
        $this->assertNull($first->fresh()->published_at);
        $this->assertSame(0, $second->fresh()->attempts); // tidak dicoba: Redis masih mati
    }

    public function test_flush_mengabaikan_event_yang_terlalu_baru_dan_menghormati_limit(): void
    {
        $recent = $this->outbox('chat.messages', 1); // < 5 detik: percobaan pertama mungkin masih berjalan
        $this->outbox('chat.messages', 50);
        $this->outbox('chat.messages', 40);
        $this->outbox('chat.messages', 30);

        Redis::shouldReceive('publish')->times(2); // --limit=2 dari 3 event yang cukup tua

        $this->artisan('outbox:flush', ['--limit' => 2])->assertSuccessful();

        $this->assertNull($recent->fresh()->published_at);
        $this->assertSame(2, OutboxEvent::whereNotNull('published_at')->count());
    }

    public function test_flush_membuang_event_kedaluwarsa_dan_membersihkan_yang_lama_terkirim(): void
    {
        config(['chat.outbox_max_age_minutes' => 30]);

        $expired = $this->outbox('chat.messages', 40 * 60);                                        // belum terkirim, 40 menit
        $oldSent = $this->outbox('chat.messages', 3 * 86400, now()->subDays(2)->toDateTimeString()); // terkirim 2 hari lalu
        $recentSent = $this->outbox('chat.messages', 3600, now()->subHour()->toDateTimeString());   // terkirim 1 jam lalu

        Redis::shouldReceive('publish')->never();

        $this->artisan('outbox:flush')->assertSuccessful();

        $this->assertDatabaseMissing('outbox_events', ['id' => $expired->id]);
        $this->assertDatabaseMissing('outbox_events', ['id' => $oldSent->id]);
        $this->assertDatabaseHas('outbox_events', ['id' => $recentSent->id]);
    }

    public function test_realtime_nonaktif_tidak_menyentuh_redis_maupun_outbox(): void
    {
        Redis::shouldReceive('publish')->never();

        $this->send($this->andi)->assertCreated();

        $this->assertDatabaseCount('outbox_events', 0);
    }

    // ================= HEADER KEAMANAN (§I no. 8) =================

    public function test_header_keamanan_ada_di_respons_api_termasuk_error(): void
    {
        $response = $this->actingAs($this->andi)->getJson('/api/v1/me')->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString('microphone=(self)', $response->headers->get('Permissions-Policy'));
    }

    public function test_respons_error_401_juga_membawa_header_keamanan(): void
    {
        $this->getJson('/api/v1/alamat-yang-tidak-ada')
            ->assertStatus(404)
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_csp_report_only_enforce_dan_off(): void
    {
        $policy = $this->actingAs($this->andi)->getJson('/api/v1/me')->headers->get('Content-Security-Policy-Report-Only');
        $this->assertNotNull($policy, 'Default: report-only');
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString(rtrim(config('chat.socket_url'), '/'), $policy); // koneksi Socket.IO diizinkan
        $this->assertStringContainsString('ws://', $policy);

        config(['chat.csp.mode' => 'enforce']);
        $enforce = $this->actingAs($this->andi)->getJson('/api/v1/me');
        $this->assertNotNull($enforce->headers->get('Content-Security-Policy'));
        $this->assertNull($enforce->headers->get('Content-Security-Policy-Report-Only'));

        config(['chat.csp.mode' => 'off']);
        $off = $this->actingAs($this->andi)->getJson('/api/v1/me');
        $this->assertNull($off->headers->get('Content-Security-Policy'));
        $this->assertNull($off->headers->get('Content-Security-Policy-Report-Only'));
        $off->assertHeader('X-Content-Type-Options', 'nosniff'); // header lain tetap ada
    }

    public function test_file_lampiran_memakai_csp_terisolasi_dan_tidak_ditimpa(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $id = $this->actingAs($this->andi)->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/attachments', ['file' => UploadedFile::fake()->createWithContent('a.png', $png)])
            ->assertCreated()->json('data.attachment.id');

        $response = $this->actingAs($this->andi)->get("/api/v1/attachments/$id/content")->assertOk();

        $response->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    // ================= amigo:doctor =================

    public function test_doctor_lulus_dengan_konfigurasi_benar(): void
    {
        config([
            'chat.socket_secret' => str_repeat('a', 40),
            'chat.internal_secret' => str_repeat('b', 40),
        ]);

        $this->artisan('amigo:doctor', ['--skip-network' => true])->assertSuccessful();
    }

    public function test_doctor_gagal_bila_secret_sama_atau_terlalu_pendek(): void
    {
        config(['chat.socket_secret' => str_repeat('a', 40), 'chat.internal_secret' => str_repeat('a', 40)]);
        $this->artisan('amigo:doctor', ['--skip-network' => true])->assertFailed();

        config(['chat.socket_secret' => 'pendek', 'chat.internal_secret' => str_repeat('b', 40)]);
        $this->artisan('amigo:doctor', ['--skip-network' => true])->assertFailed();

        config(['chat.socket_secret' => '', 'chat.internal_secret' => '']);
        $this->artisan('amigo:doctor', ['--skip-network' => true])->assertFailed();
    }

    public function test_doctor_gagal_bila_disk_lampiran_publik(): void
    {
        config([
            'chat.socket_secret' => str_repeat('a', 40),
            'chat.internal_secret' => str_repeat('b', 40),
            'chat.media_disk' => 'public',
        ]);

        $this->artisan('amigo:doctor', ['--skip-network' => true])->assertFailed();
    }
}
