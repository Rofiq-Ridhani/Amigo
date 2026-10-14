<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Uji Fase 2: percakapan, pesan, riwayat, resync, publish Redis, delivered (kontrak §D.4, §D.5, §D.9).
 */
class Phase2MessagesTest extends TestCase
{
    use RefreshDatabase;

    private const INTERNAL_SECRET = 'rahasia-internal-test';

    private User $andi;

    private User $budi;

    private User $citra;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'chat.realtime_enabled' => false,
            'chat.internal_secret' => self::INTERNAL_SECRET,
        ]);

        $this->andi = User::factory()->create(['name' => 'Andi Pratama']);
        $this->budi = User::factory()->create(['name' => 'Budi Santoso']);
        $this->citra = User::factory()->create(['name' => 'Citra Dewi']);
    }

    private function makeConversation(User $a, User $b): Conversation
    {
        return Conversation::between($a->id, $b->id);
    }

    private function makeMessage(Conversation $c, User $from, User $to, string $body = 'Halo', array $extra = []): Message
    {
        $message = Message::create(array_merge([
            'conversation_id' => $c->id,
            'sender_id' => $from->id,
            'recipient_id' => $to->id,
            'client_uuid' => (string) Str::uuid(),
            'body' => $body,
            'revision' => 1,
        ], $extra));

        $c->forceFill(['last_message_id' => $message->id, 'last_message_at' => $message->created_at])->save();

        return $message;
    }

    // ---------- Percakapan ----------

    public function test_membuat_percakapan_lalu_mengambil_yang_sama(): void
    {
        $first = $this->actingAs($this->budi)->postJson('/api/v1/conversations', ['user_id' => $this->andi->id])
            ->assertCreated()
            ->assertJsonPath('data.conversation.peer.id', $this->andi->id)
            ->assertJsonPath('data.conversation.unread_count', 0);

        $this->actingAs($this->budi)->postJson('/api/v1/conversations', ['user_id' => $this->andi->id])
            ->assertOk()
            ->assertJsonPath('data.conversation.id', $first->json('data.conversation.id'));

        // Dari sisi Andi: pasangan yang sama, bukan baris baru
        $this->actingAs($this->andi)->postJson('/api/v1/conversations', ['user_id' => $this->budi->id])->assertOk();

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseHas('conversations', [
            'user_one_id' => min($this->andi->id, $this->budi->id),
            'user_two_id' => max($this->andi->id, $this->budi->id),
        ]);
    }

    public function test_percakapan_dengan_diri_sendiri_ditolak(): void
    {
        $this->actingAs($this->andi)->postJson('/api/v1/conversations', ['user_id' => $this->andi->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_percakapan_dengan_user_tidak_ada_ditolak(): void
    {
        $this->actingAs($this->andi)->postJson('/api/v1/conversations', ['user_id' => 99999])->assertStatus(422);
    }

    public function test_daftar_percakapan_hanya_yang_punya_pesan_dengan_unread_dan_tanpa_email(): void
    {
        $withMessages = $this->makeConversation($this->andi, $this->budi);
        $this->makeConversation($this->andi, $this->citra); // kosong
        $this->makeMessage($withMessages, $this->budi, $this->andi, 'Satu');
        $this->makeMessage($withMessages, $this->budi, $this->andi, 'Dua');
        $this->makeMessage($withMessages, $this->andi, $this->budi, 'Balasan');

        $response = $this->actingAs($this->andi)->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonCount(1, 'data.conversations')
            ->assertJsonPath('data.conversations.0.peer.id', $this->budi->id)
            ->assertJsonPath('data.conversations.0.unread_count', 2)
            ->assertJsonPath('data.conversations.0.last_message.body', 'Balasan');

        $this->assertStringNotContainsString('email', $response->getContent());
    }

    // ---------- Kirim pesan ----------

    public function test_kirim_pesan_tersimpan_dan_status_sent(): void
    {
        $c = $this->makeConversation($this->andi, $this->budi);
        $uuid = (string) Str::uuid();

        $this->actingAs($this->andi)->postJson("/api/v1/conversations/{$c->id}/messages", [
            'client_uuid' => $uuid,
            'body' => 'Halo, apakah sudah selesai?',
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.message.body', 'Halo, apakah sudah selesai?')
            ->assertJsonPath('data.message.sender_id', $this->andi->id)
            ->assertJsonPath('data.message.recipient_id', $this->budi->id)
            ->assertJsonPath('data.message.status', 'sent')
            ->assertJsonPath('data.message.client_uuid', $uuid)
            ->assertJsonPath('data.message.revision', 1);

        $this->assertDatabaseHas('messages', ['conversation_id' => $c->id, 'client_uuid' => $uuid]);

        $c->refresh();
        $this->assertNotNull($c->last_message_id);
        $this->assertNotNull($c->last_message_at);
    }

    public function test_kirim_pesan_mem_publish_message_new_ke_redis(): void
    {
        config(['chat.realtime_enabled' => true]);
        $c = $this->makeConversation($this->andi, $this->budi);

        $published = [];
        Redis::shouldReceive('publish')->once()->with('chat.notifications', Mockery::any()); // notification:new
        Redis::shouldReceive('publish')
            ->twice() // message:new lalu unread:update
            ->with('chat.messages', Mockery::on(function ($json) use (&$published) {
                $published[] = json_decode($json, true);

                return true;
            }));

        $this->actingAs($this->andi)->postJson("/api/v1/conversations/{$c->id}/messages", [
            'client_uuid' => (string) Str::uuid(),
            'body' => 'Halo',
        ])->assertCreated();

        $captured = $published[0];
        $this->assertSame('message:new', $captured['event']);
        $this->assertEqualsCanonicalizing([$this->andi->id, $this->budi->id], $captured['to']);
        $this->assertSame($this->budi->id, $captured['ack_user_id']);
        $this->assertSame('Halo', $captured['data']['message']['body']);
        $this->assertNotEmpty($captured['event_id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $captured['occurred_at']);

        $this->assertSame('unread:update', $published[1]['event']);
        $this->assertSame([$this->budi->id], $published[1]['to']);
        $this->assertSame(1, $published[1]['data']['unread_count']);
    }

    public function test_redis_gagal_pesan_tetap_tersimpan_201(): void
    {
        config(['chat.realtime_enabled' => true]);
        $c = $this->makeConversation($this->andi, $this->budi);

        Redis::shouldReceive('publish')->times(3)->andThrow(new \RuntimeException('Connection refused'));

        $this->actingAs($this->andi)->postJson("/api/v1/conversations/{$c->id}/messages", [
            'client_uuid' => (string) Str::uuid(),
            'body' => 'Tetap tersimpan',
        ])->assertCreated();

        $this->assertDatabaseHas('messages', ['body' => 'Tetap tersimpan']);
    }

    public function test_client_uuid_sama_idempoten_tidak_menyimpan_dan_publish_ulang(): void
    {
        config(['chat.realtime_enabled' => true]);
        $c = $this->makeConversation($this->andi, $this->budi);
        $uuid = (string) Str::uuid();

        Redis::shouldReceive('publish')->times(3); // message:new + unread:update + notification:new, HANYA dari kiriman pertama

        $first = $this->actingAs($this->andi)->postJson("/api/v1/conversations/{$c->id}/messages", [
            'client_uuid' => $uuid, 'body' => 'Sekali saja',
        ])->assertCreated();

        $second = $this->actingAs($this->andi)->postJson("/api/v1/conversations/{$c->id}/messages", [
            'client_uuid' => $uuid, 'body' => 'Sekali saja',
        ])->assertOk();

        $this->assertSame($first->json('data.message.id'), $second->json('data.message.id'));
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_bukan_peserta_tidak_bisa_kirim_atau_baca_404(): void
    {
        $c = $this->makeConversation($this->andi, $this->budi);
        $this->makeMessage($c, $this->andi, $this->budi, 'Rahasia');

        $this->actingAs($this->citra)->postJson("/api/v1/conversations/{$c->id}/messages", [
            'client_uuid' => (string) Str::uuid(), 'body' => 'Menyusup',
        ])->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');

        $this->actingAs($this->citra)->getJson("/api/v1/conversations/{$c->id}/messages")
            ->assertStatus(404);

        $this->assertDatabaseMissing('messages', ['body' => 'Menyusup']);
    }

    public function test_validasi_pesan(): void
    {
        $c = $this->makeConversation($this->andi, $this->budi);
        $url = "/api/v1/conversations/{$c->id}/messages";

        $this->actingAs($this->andi)->postJson($url, ['client_uuid' => (string) Str::uuid(), 'body' => '   '])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->actingAs($this->andi)->postJson($url, ['client_uuid' => 'bukan-uuid', 'body' => 'x'])->assertStatus(422);

        $this->actingAs($this->andi)->postJson($url, ['body' => 'tanpa uuid'])->assertStatus(422);

        $this->actingAs($this->andi)->postJson($url, [
            'client_uuid' => (string) Str::uuid(),
            'body' => str_repeat('a', config('chat.message_max_length') + 1),
        ])->assertStatus(422);

        $this->actingAs($this->andi)->postJson($url, [
            'client_uuid' => (string) Str::uuid(), 'body' => 'x', 'attachment_ids' => [1],
        ])->assertStatus(422);
    }

    public function test_balas_pesan_dan_balasan_ke_percakapan_lain_ditolak(): void
    {
        $c = $this->makeConversation($this->andi, $this->budi);
        $other = $this->makeConversation($this->andi, $this->citra);
        $original = $this->makeMessage($c, $this->budi, $this->andi, 'Besok jam 9 ya');
        $foreign = $this->makeMessage($other, $this->citra, $this->andi, 'Pesan lain');
        $url = "/api/v1/conversations/{$c->id}/messages";

        $this->actingAs($this->andi)->postJson($url, [
            'client_uuid' => (string) Str::uuid(), 'body' => 'Siap', 'reply_to_id' => $original->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.message.reply_to.id', $original->id)
            ->assertJsonPath('data.message.reply_to.body_preview', 'Besok jam 9 ya')
            ->assertJsonPath('data.message.reply_to.deleted', false);

        $this->actingAs($this->andi)->postJson($url, [
            'client_uuid' => (string) Str::uuid(), 'body' => 'Salah', 'reply_to_id' => $foreign->id,
        ])->assertStatus(422);
    }

    // ---------- Riwayat & resync ----------

    public function test_riwayat_urut_lama_ke_baru_dengan_paging_before_id(): void
    {
        $c = $this->makeConversation($this->andi, $this->budi);
        $ids = [];
        foreach (range(1, 5) as $i) {
            $ids[] = $this->makeMessage($c, $this->andi, $this->budi, "Pesan $i")->id;
        }
        $url = "/api/v1/conversations/{$c->id}/messages";

        $page1 = $this->actingAs($this->budi)->getJson("$url?limit=2")->assertOk();
        $this->assertSame([$ids[3], $ids[4]], array_column($page1->json('data.messages'), 'id'));
        $this->assertTrue($page1->json('meta.has_more_before'));
        $this->assertSame($ids[3], $page1->json('meta.oldest_id'));
        $this->assertSame($ids[4], $page1->json('meta.newest_id'));
        $this->assertNotEmpty($page1->json('meta.server_time'));

        $page2 = $this->actingAs($this->budi)->getJson("$url?limit=2&before_id={$ids[3]}")->assertOk();
        $this->assertSame([$ids[1], $ids[2]], array_column($page2->json('data.messages'), 'id'));
        $this->assertTrue($page2->json('meta.has_more_before'));

        $page3 = $this->actingAs($this->budi)->getJson("$url?limit=2&before_id={$ids[1]}")->assertOk();
        $this->assertSame([$ids[0]], array_column($page3->json('data.messages'), 'id'));
        $this->assertFalse($page3->json('meta.has_more_before'));
    }

    public function test_pesan_terhapus_tampil_sebagai_tombstone(): void
    {
        $c = $this->makeConversation($this->andi, $this->budi);
        $m = $this->makeMessage($c, $this->andi, $this->budi, 'Akan dihapus');
        $m->delete();

        $this->actingAs($this->budi)->getJson("/api/v1/conversations/{$c->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.messages.0.id', $m->id)
            ->assertJsonPath('data.messages.0.body', null)
            ->assertJsonPath('data.messages.0.attachments', [])
            ->assertJsonPath('data.messages.0.reply_to', null);
    }

    public function test_resync_updated_since_hanya_mengembalikan_yang_berubah(): void
    {
        $c = $this->makeConversation($this->andi, $this->budi);
        $old = $this->makeMessage($c, $this->andi, $this->budi, 'Lama');
        Message::where('id', $old->id)->update(['updated_at' => now()->subDay()]);
        $fresh = $this->makeMessage($c, $this->andi, $this->budi, 'Baru');

        $since = now()->subHour()->utc()->format('Y-m-d\TH:i:s\Z');

        $response = $this->actingAs($this->budi)
            ->getJson("/api/v1/conversations/{$c->id}/messages?updated_since=$since")
            ->assertOk()
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.id', $fresh->id);

        $this->assertFalse($response->json('meta.has_more'));
    }

    public function test_before_id_dan_updated_since_tidak_boleh_bersamaan(): void
    {
        $c = $this->makeConversation($this->andi, $this->budi);

        $this->actingAs($this->andi)
            ->getJson("/api/v1/conversations/{$c->id}/messages?before_id=5&updated_since=2026-10-14T00:00:00Z")
            ->assertStatus(422);
    }

    public function test_api_pesan_butuh_login(): void
    {
        $this->getJson('/api/v1/conversations')->assertStatus(401);
        $this->postJson('/api/v1/conversations/1/messages', [])->assertStatus(401);
    }

    // ---------- Internal: delivered ----------

    /** @param array<string,mixed> $payload */
    private function signedDelivered(array $payload)
    {
        $raw = json_encode($payload);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$raw, self::INTERNAL_SECRET);

        return $this->call('POST', '/internal/v1/messages/delivered', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_INTERNAL_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_INTERNAL_SIGNATURE' => $signature,
        ], $raw);
    }

    public function test_delivered_menandai_pesan_milik_penerima_dan_idempoten(): void
    {
        $c = $this->makeConversation($this->andi, $this->budi);
        $m = $this->makeMessage($c, $this->andi, $this->budi);

        $first = $this->signedDelivered(['recipient_id' => $this->budi->id, 'message_ids' => [$m->id]])
            ->assertOk()
            ->assertJsonPath('data.delivered.0.id', $m->id)
            ->assertJsonPath('data.delivered.0.sender_id', $this->andi->id)
            ->assertJsonPath('data.delivered.0.conversation_id', $c->id);

        $this->assertNotEmpty($first->json('data.delivered.0.delivered_at'));

        $m->refresh();
        $this->assertNotNull($m->delivered_at);
        $this->assertSame(2, $m->revision);
        $this->assertSame('delivered', $m->status());

        // Panggilan ulang: tidak ada yang berubah
        $this->signedDelivered(['recipient_id' => $this->budi->id, 'message_ids' => [$m->id]])
            ->assertOk()
            ->assertJsonCount(0, 'data.delivered');
        $this->assertSame(2, $m->fresh()->revision);
    }

    public function test_delivered_menolak_penerima_yang_bukan_pemilik_pesan(): void
    {
        $c = $this->makeConversation($this->andi, $this->budi);
        $m = $this->makeMessage($c, $this->andi, $this->budi);

        // Citra bukan penerima pesan ini
        $this->signedDelivered(['recipient_id' => $this->citra->id, 'message_ids' => [$m->id]])
            ->assertOk()
            ->assertJsonCount(0, 'data.delivered');

        $this->assertNull($m->fresh()->delivered_at);
    }

    public function test_delivered_tanpa_tanda_tangan_ditolak(): void
    {
        $this->postJson('/internal/v1/messages/delivered', ['recipient_id' => 1, 'message_ids' => [1]])
            ->assertStatus(401);
    }

    public function test_delivered_validasi_payload(): void
    {
        $this->signedDelivered(['recipient_id' => $this->budi->id, 'message_ids' => []])->assertStatus(422);
        $this->signedDelivered(['recipient_id' => 99999, 'message_ids' => [1]])->assertStatus(422);
    }
}
