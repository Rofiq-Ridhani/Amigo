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
 * Uji Fase 3: read receipt, unread count, event realtime (kontrak §D.7, §H.2, §H.4).
 */
class Phase3ReadTest extends TestCase
{
    use RefreshDatabase;

    private User $andi;

    private User $budi;

    private User $citra;

    protected function setUp(): void
    {
        parent::setUp();

        config(['chat.realtime_enabled' => false]);

        $this->andi = User::factory()->create(['name' => 'Andi Pratama']);
        $this->budi = User::factory()->create(['name' => 'Budi Santoso']);
        $this->citra = User::factory()->create(['name' => 'Citra Dewi']);
    }

    private function makeMessage(Conversation $c, User $from, User $to, string $body = 'Halo'): Message
    {
        $message = Message::create([
            'conversation_id' => $c->id,
            'sender_id' => $from->id,
            'recipient_id' => $to->id,
            'client_uuid' => (string) Str::uuid(),
            'body' => $body,
            'revision' => 1,
        ]);

        $c->forceFill(['last_message_id' => $message->id, 'last_message_at' => $message->created_at])->save();

        return $message;
    }

    public function test_mark_read_menandai_pesan_milik_pembaca_sampai_up_to(): void
    {
        $c = Conversation::between($this->andi->id, $this->budi->id);
        $m1 = $this->makeMessage($c, $this->andi, $this->budi, 'Satu');
        $m2 = $this->makeMessage($c, $this->andi, $this->budi, 'Dua');
        $m3 = $this->makeMessage($c, $this->andi, $this->budi, 'Tiga');
        $mine = $this->makeMessage($c, $this->budi, $this->andi, 'Pesan Budi sendiri');

        $this->actingAs($this->budi)->postJson("/api/v1/conversations/{$c->id}/read", ['up_to_message_id' => $m2->id])
            ->assertOk()
            ->assertJsonPath('data.conversation_id', $c->id)
            ->assertJsonPath('data.read_count', 2)
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.total_unread', 1);

        $this->assertNotNull($m1->fresh()->read_at);
        $this->assertNotNull($m1->fresh()->delivered_at); // dibaca => delivered
        $this->assertSame(2, $m1->fresh()->revision);
        $this->assertSame('read', $m1->fresh()->status());
        $this->assertNotNull($m2->fresh()->read_at);
        $this->assertNull($m3->fresh()->read_at);   // setelah up_to: tetap belum dibaca
        $this->assertNull($mine->fresh()->read_at); // pesan yang DIKIRIM Budi tidak tersentuh
    }

    public function test_mark_read_idempoten(): void
    {
        $c = Conversation::between($this->andi->id, $this->budi->id);
        $m = $this->makeMessage($c, $this->andi, $this->budi);
        $url = "/api/v1/conversations/{$c->id}/read";

        $first = $this->actingAs($this->budi)->postJson($url, ['up_to_message_id' => $m->id])->assertOk();
        $this->assertSame(1, $first->json('data.read_count'));
        $this->assertNotNull($first->json('data.read_at'));

        $second = $this->actingAs($this->budi)->postJson($url, ['up_to_message_id' => $m->id])
            ->assertOk()
            ->assertJsonPath('data.read_count', 0)
            ->assertJsonPath('data.unread_count', 0)
            ->assertJsonPath('data.read_at', null);

        $this->assertSame(2, $m->fresh()->revision); // tidak naik lagi
    }

    public function test_mark_read_publish_message_read_ke_pengirim_dan_unread_update_ke_pembaca(): void
    {
        $c = Conversation::between($this->andi->id, $this->budi->id);
        $this->makeMessage($c, $this->andi, $this->budi);
        $last = $this->makeMessage($c, $this->andi, $this->budi);
        $other = Conversation::between($this->citra->id, $this->budi->id);
        $this->makeMessage($other, $this->citra, $this->budi); // percakapan lain: hanya menambah total

        config(['chat.realtime_enabled' => true]);
        $published = [];
        Redis::shouldReceive('publish')
            ->twice()
            ->with('chat.messages', Mockery::on(function ($json) use (&$published) {
                $published[] = json_decode($json, true);

                return true;
            }));

        $this->actingAs($this->budi)->postJson("/api/v1/conversations/{$c->id}/read", ['up_to_message_id' => $last->id])->assertOk();

        $read = $published[0];
        $this->assertSame('message:read', $read['event']);
        $this->assertSame([$this->andi->id], $read['to']);
        $this->assertNull($read['ack_user_id']);
        $this->assertSame($c->id, $read['data']['conversation_id']);
        $this->assertSame($this->budi->id, $read['data']['reader_id']);
        $this->assertSame($last->id, $read['data']['up_to_message_id']);
        $this->assertNotEmpty($read['data']['read_at']);

        $unread = $published[1];
        $this->assertSame('unread:update', $unread['event']);
        $this->assertSame([$this->budi->id], $unread['to']);
        $this->assertSame($c->id, $unread['data']['conversation_id']);
        $this->assertSame(0, $unread['data']['unread_count']);  // percakapan ini sudah habis dibaca
        $this->assertSame(1, $unread['data']['total_unread']);   // masih ada 1 dari Citra
    }

    public function test_kirim_pesan_mem_publish_unread_update_dengan_angka_absolut(): void
    {
        $c = Conversation::between($this->andi->id, $this->budi->id);
        $other = Conversation::between($this->citra->id, $this->budi->id);
        $this->makeMessage($other, $this->citra, $this->budi, 'Dari Citra'); // 1 unread di percakapan lain

        config(['chat.realtime_enabled' => true]);
        $published = [];
        Redis::shouldReceive('publish')
            ->times(4) // 2 pesan x (message:new + unread:update)
            ->with('chat.messages', Mockery::on(function ($json) use (&$published) {
                $published[] = json_decode($json, true);

                return true;
            }));

        foreach (['Satu', 'Dua'] as $body) {
            $this->actingAs($this->andi)->postJson("/api/v1/conversations/{$c->id}/messages", [
                'client_uuid' => (string) Str::uuid(), 'body' => $body,
            ])->assertCreated();
        }

        $updates = array_values(array_filter($published, fn ($e) => $e['event'] === 'unread:update'));
        $this->assertCount(2, $updates);
        $this->assertSame(1, $updates[0]['data']['unread_count']);
        $this->assertSame(2, $updates[1]['data']['unread_count']);
        $this->assertSame(3, $updates[1]['data']['total_unread']); // 2 + 1 dari percakapan Citra
        $this->assertSame([$this->budi->id], $updates[1]['to']);
    }

    public function test_bukan_peserta_404_dan_up_to_dari_percakapan_lain_ditolak(): void
    {
        $c = Conversation::between($this->andi->id, $this->budi->id);
        $other = Conversation::between($this->andi->id, $this->citra->id);
        $m = $this->makeMessage($c, $this->andi, $this->budi);
        $foreign = $this->makeMessage($other, $this->andi, $this->citra);

        $this->actingAs($this->citra)->postJson("/api/v1/conversations/{$c->id}/read", ['up_to_message_id' => $m->id])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
        $this->assertNull($m->fresh()->read_at);

        $this->actingAs($this->budi)->postJson("/api/v1/conversations/{$c->id}/read", ['up_to_message_id' => $foreign->id])
            ->assertStatus(422);

        $this->actingAs($this->budi)->postJson("/api/v1/conversations/{$c->id}/read", [])->assertStatus(422);
    }

    public function test_unread_counts_per_percakapan_tanpa_pesan_terhapus_dan_pesan_sendiri(): void
    {
        $c1 = Conversation::between($this->andi->id, $this->budi->id);
        $c2 = Conversation::between($this->citra->id, $this->budi->id);
        $c3 = Conversation::between($this->andi->id, $this->citra->id);

        $this->makeMessage($c1, $this->andi, $this->budi);
        $this->makeMessage($c1, $this->andi, $this->budi);
        $deleted = $this->makeMessage($c1, $this->andi, $this->budi);
        $deleted->delete();
        $this->makeMessage($c1, $this->budi, $this->andi, 'milik Budi sendiri');
        $this->makeMessage($c2, $this->citra, $this->budi);
        $this->makeMessage($c3, $this->andi, $this->citra); // bukan untuk Budi

        $response = $this->actingAs($this->budi)->getJson('/api/v1/unread-counts')
            ->assertOk()
            ->assertJsonPath('data.total', 3);

        $this->assertSame(
            [
                ['conversation_id' => $c1->id, 'unread_count' => 2],
                ['conversation_id' => $c2->id, 'unread_count' => 1],
            ],
            $response->json('data.by_conversation'),
        );
    }

    public function test_unread_counts_kosong(): void
    {
        $this->actingAs($this->budi)->getJson('/api/v1/unread-counts')
            ->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.by_conversation', []);
    }

    public function test_endpoint_baca_butuh_login(): void
    {
        $this->getJson('/api/v1/unread-counts')->assertStatus(401);
        $this->postJson('/api/v1/conversations/1/read', [])->assertStatus(401);
    }

    public function test_status_terlihat_oleh_pengirim_dan_unread_di_daftar_percakapan_turun(): void
    {
        $c = Conversation::between($this->andi->id, $this->budi->id);
        $m = $this->makeMessage($c, $this->andi, $this->budi);

        $this->actingAs($this->andi)->getJson("/api/v1/conversations/{$c->id}/messages")
            ->assertJsonPath('data.messages.0.status', 'sent');

        $this->actingAs($this->budi)->getJson('/api/v1/conversations')
            ->assertJsonPath('data.conversations.0.unread_count', 1);

        $this->actingAs($this->budi)->postJson("/api/v1/conversations/{$c->id}/read", ['up_to_message_id' => $m->id])->assertOk();

        $this->actingAs($this->andi)->getJson("/api/v1/conversations/{$c->id}/messages")
            ->assertJsonPath('data.messages.0.status', 'read')
            ->assertJsonPath('data.messages.0.revision', 2);

        $this->actingAs($this->budi)->getJson('/api/v1/conversations')
            ->assertJsonPath('data.conversations.0.unread_count', 0);
    }
}
