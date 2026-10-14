<?php

namespace Tests\Feature;

use App\Models\ChatNotification;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Uji Fase 4: notifikasi (kontrak §C.6, §D.8, §H.5).
 */
class Phase4NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $andi;

    private User $budi;

    private User $citra;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        config(['chat.realtime_enabled' => false]);

        $this->andi = User::factory()->create(['name' => 'Andi Pratama']);
        $this->budi = User::factory()->create(['name' => 'Budi Santoso']);
        $this->citra = User::factory()->create(['name' => 'Citra Dewi']);
        $this->conversation = Conversation::between($this->andi->id, $this->budi->id);
    }

    /** Kirim pesan lewat API (membuat pesan + notifikasi). @return array<string,mixed> message */
    private function send(User $from, string $body, ?Conversation $conversation = null): array
    {
        $conversation ??= $this->conversation;

        return $this->actingAs($from)
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", [
                'client_uuid' => (string) Str::uuid(),
                'body' => $body,
            ])
            ->assertCreated()
            ->json('data.message');
    }

    // ---------- Pembuatan ----------

    public function test_pesan_baru_membuat_satu_notifikasi_untuk_penerima_saja(): void
    {
        $message = $this->send($this->andi, 'Halo, apakah sudah selesai?');

        $this->assertDatabaseCount('notifications', 1);

        $n = ChatNotification::first();
        $this->assertSame($this->budi->id, $n->user_id);          // penerima, bukan pengirim
        $this->assertSame('chat.message', $n->type);
        $this->assertSame('Pesan baru dari Andi Pratama', $n->title);
        $this->assertSame('Halo, apakah sudah selesai?', $n->body);
        $this->assertNull($n->read_at);
        $this->assertSame($message['id'], $n->data['message_id']);
        $this->assertSame($this->conversation->id, $n->data['conversation_id']);
        $this->assertSame($this->andi->id, $n->data['sender_id']);
        $this->assertSame('/chat?conversation='.$this->conversation->id, $n->data['url']);
        $this->assertTrue(Str::isUuid($n->id));
    }

    public function test_body_notifikasi_dipotong_100_karakter(): void
    {
        $this->send($this->andi, str_repeat('a', 150));

        $this->assertSame(100, mb_strlen(ChatNotification::first()->body));
    }

    public function test_kirim_ulang_dengan_client_uuid_sama_tidak_membuat_notifikasi_ganda(): void
    {
        $uuid = (string) Str::uuid();
        $url = "/api/v1/conversations/{$this->conversation->id}/messages";

        $this->actingAs($this->andi)->postJson($url, ['client_uuid' => $uuid, 'body' => 'Sekali'])->assertCreated();
        $this->actingAs($this->andi)->postJson($url, ['client_uuid' => $uuid, 'body' => 'Sekali'])->assertOk();

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_publish_notification_new_ke_channel_notifikasi_dengan_ack_penerima(): void
    {
        config(['chat.realtime_enabled' => true]);

        $captured = null;
        Redis::shouldReceive('publish')->twice()->with('chat.messages', Mockery::any()); // message:new, unread:update
        Redis::shouldReceive('publish')
            ->once()
            ->with('chat.notifications', Mockery::on(function ($json) use (&$captured) {
                $captured = json_decode($json, true);

                return true;
            }));

        $this->send($this->andi, 'Halo');

        $this->assertSame('notification:new', $captured['event']);
        $this->assertSame([$this->budi->id], $captured['to']);
        $this->assertSame($this->budi->id, $captured['ack_user_id']);
        $this->assertSame(1, $captured['data']['unread_count']);
        $this->assertSame('Pesan baru dari Andi Pratama', $captured['data']['notification']['title']);
        $this->assertSame('chat.message', $captured['data']['notification']['type']);
        $this->assertNull($captured['data']['notification']['read_at']);
        $this->assertTrue(Str::isUuid($captured['data']['notification']['id']));
    }

    // ---------- Daftar ----------

    public function test_daftar_notifikasi_hanya_milik_sendiri_terbaru_dulu_dengan_unread_count(): void
    {
        $this->send($this->andi, 'Pertama');
        $this->send($this->andi, 'Kedua');
        $other = Conversation::between($this->citra->id, $this->andi->id);
        $this->send($this->citra, 'Untuk Andi', $other); // notifikasi milik Andi, bukan Budi
        ChatNotification::where('body', 'Pertama')->update(['created_at' => now()->subMinute()]); // urutan pasti

        $response = $this->actingAs($this->budi)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(2, 'data.notifications')
            ->assertJsonPath('data.unread_count', 2)
            ->assertJsonPath('meta.total', 2);

        $this->assertSame(['Kedua', 'Pertama'], array_column($response->json('data.notifications'), 'body'));
        $this->assertStringNotContainsString('Untuk Andi', $response->getContent());
    }

    public function test_filter_unread(): void
    {
        $this->send($this->andi, 'Satu');
        $this->send($this->andi, 'Dua');
        $first = ChatNotification::where('body', 'Satu')->first();
        $first->forceFill(['read_at' => now()])->save();

        $this->actingAs($this->budi)->getJson('/api/v1/notifications?unread=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.notifications')
            ->assertJsonPath('data.notifications.0.body', 'Dua')
            ->assertJsonPath('data.unread_count', 1);

        $this->actingAs($this->budi)->getJson('/api/v1/notifications')
            ->assertJsonCount(2, 'data.notifications');
    }

    public function test_notifikasi_offline_tetap_ada_saat_login_kembali(): void
    {
        // Realtime mati (Redis/Node tidak ada), notifikasi tetap tersimpan & terbaca lewat REST
        $this->send($this->andi, 'Saat kamu offline');

        $this->actingAs($this->budi)->getJson('/api/v1/notifications?unread=1')
            ->assertOk()
            ->assertJsonPath('data.notifications.0.body', 'Saat kamu offline');
    }

    // ---------- Tandai dibaca ----------

    public function test_tandai_satu_notifikasi_dibaca_idempoten(): void
    {
        $this->send($this->andi, 'Halo');
        $id = ChatNotification::first()->id;

        $first = $this->actingAs($this->budi)->postJson("/api/v1/notifications/{$id}/read")
            ->assertOk()
            ->assertJsonPath('data.notification.id', $id)
            ->assertJsonPath('data.unread_count', 0);
        $this->assertNotNull($first->json('data.notification.read_at'));

        $readAt = ChatNotification::first()->read_at;

        $this->actingAs($this->budi)->postJson("/api/v1/notifications/{$id}/read")
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->assertEquals($readAt, ChatNotification::first()->read_at); // tidak berubah
    }

    public function test_tidak_bisa_menandai_notifikasi_orang_lain_atau_yang_tidak_ada(): void
    {
        $this->send($this->andi, 'Halo');
        $id = ChatNotification::first()->id; // milik Budi

        $this->actingAs($this->citra)->postJson("/api/v1/notifications/{$id}/read")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
        $this->assertNull(ChatNotification::first()->read_at);

        $this->actingAs($this->budi)->postJson('/api/v1/notifications/'.Str::uuid().'/read')->assertStatus(404);
        $this->actingAs($this->budi)->postJson('/api/v1/notifications/bukan-uuid/read')->assertStatus(404);
    }

    public function test_read_all_hanya_milik_sendiri(): void
    {
        $this->send($this->andi, 'Satu');
        $this->send($this->andi, 'Dua');
        $other = Conversation::between($this->citra->id, $this->andi->id);
        $this->send($this->citra, 'Milik Andi', $other);

        $this->actingAs($this->budi)->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.updated', 2)
            ->assertJsonPath('data.unread_count', 0);

        $this->assertSame(1, ChatNotification::whereNull('read_at')->count()); // milik Andi tetap
        $this->assertSame($this->andi->id, ChatNotification::whereNull('read_at')->first()->user_id);

        $this->actingAs($this->budi)->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.updated', 0); // idempoten
    }

    public function test_publish_notification_read_saat_tandai_satu_dan_semua(): void
    {
        $this->send($this->andi, 'Satu');
        $this->send($this->andi, 'Dua');
        $id = ChatNotification::where('body', 'Satu')->first()->id;

        config(['chat.realtime_enabled' => true]);
        $published = [];
        Redis::shouldReceive('publish')
            ->twice()
            ->with('chat.notifications', Mockery::on(function ($json) use (&$published) {
                $published[] = json_decode($json, true);

                return true;
            }));

        $this->actingAs($this->budi)->postJson("/api/v1/notifications/{$id}/read")->assertOk();
        $this->actingAs($this->budi)->postJson('/api/v1/notifications/read-all')->assertOk();

        $this->assertSame('notification:read', $published[0]['event']);
        $this->assertSame([$this->budi->id], $published[0]['to']);
        $this->assertSame([$id], $published[0]['data']['ids']);
        $this->assertFalse($published[0]['data']['all']);
        $this->assertSame(1, $published[0]['data']['unread_count']);

        $this->assertNull($published[1]['data']['ids']);
        $this->assertTrue($published[1]['data']['all']);
        $this->assertSame(0, $published[1]['data']['unread_count']);
    }

    // ---------- Terbaca otomatis saat pesan dibaca (§H.5) ----------

    public function test_notifikasi_ikut_terbaca_saat_pesan_dibaca_sampai_up_to(): void
    {
        $m1 = $this->send($this->andi, 'Satu');
        $m2 = $this->send($this->andi, 'Dua');
        $m3 = $this->send($this->andi, 'Tiga');

        $this->actingAs($this->budi)
            ->postJson("/api/v1/conversations/{$this->conversation->id}/read", ['up_to_message_id' => $m2['id']])
            ->assertOk();

        $unread = ChatNotification::whereNull('read_at')->get();
        $this->assertCount(1, $unread);
        $this->assertSame($m3['id'], $unread->first()->data['message_id']); // hanya yang setelah up_to

        $this->actingAs($this->budi)->getJson('/api/v1/notifications?unread=1')
            ->assertJsonPath('data.unread_count', 1);
    }

    public function test_publish_notification_read_saat_pesan_dibaca(): void
    {
        $this->send($this->andi, 'Satu');
        $last = $this->send($this->andi, 'Dua');

        config(['chat.realtime_enabled' => true]);
        $published = [];
        Redis::shouldReceive('publish')->twice()->with('chat.messages', Mockery::any()); // message:read, unread:update
        Redis::shouldReceive('publish')
            ->once()
            ->with('chat.notifications', Mockery::on(function ($json) use (&$published) {
                $published[] = json_decode($json, true);

                return true;
            }));

        $this->actingAs($this->budi)
            ->postJson("/api/v1/conversations/{$this->conversation->id}/read", ['up_to_message_id' => $last['id']])
            ->assertOk();

        $this->assertSame('notification:read', $published[0]['event']);
        $this->assertSame([$this->budi->id], $published[0]['to']);
        $this->assertCount(2, $published[0]['data']['ids']);
        $this->assertFalse($published[0]['data']['all']);
        $this->assertSame(0, $published[0]['data']['unread_count']);
    }

    public function test_membaca_pesan_tanpa_notifikasi_tidak_memublikasikan_notification_read(): void
    {
        // Notifikasi sudah ditandai terbaca lebih dulu -> tidak ada yang berubah
        $m = $this->send($this->andi, 'Halo');
        $this->actingAs($this->budi)->postJson('/api/v1/notifications/read-all')->assertOk();

        config(['chat.realtime_enabled' => true]);
        Redis::shouldReceive('publish')->twice()->with('chat.messages', Mockery::any());
        Redis::shouldReceive('publish')->never()->with('chat.notifications', Mockery::any());

        $this->actingAs($this->budi)
            ->postJson("/api/v1/conversations/{$this->conversation->id}/read", ['up_to_message_id' => $m['id']])
            ->assertOk();
    }

    // ---------- Akses ----------

    public function test_endpoint_notifikasi_butuh_login(): void
    {
        $this->getJson('/api/v1/notifications')->assertStatus(401);
        $this->postJson('/api/v1/notifications/read-all')->assertStatus(401);
        $this->postJson('/api/v1/notifications/'.Str::uuid().'/read')->assertStatus(401);
    }
}
