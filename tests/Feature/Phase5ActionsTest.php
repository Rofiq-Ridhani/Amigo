<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Uji Fase 5: edit, hapus, reaksi, endpoint internal participants (kontrak §D.5, §D.9).
 */
class Phase5ActionsTest extends TestCase
{
    use RefreshDatabase;

    private const INTERNAL_SECRET = 'rahasia-internal-test';

    private User $andi;

    private User $budi;

    private User $citra;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        config(['chat.realtime_enabled' => false, 'chat.internal_secret' => self::INTERNAL_SECRET]);

        $this->andi = User::factory()->create(['name' => 'Andi Pratama']);
        $this->budi = User::factory()->create(['name' => 'Budi Santoso']);
        $this->citra = User::factory()->create(['name' => 'Citra Dewi']);
        $this->conversation = Conversation::between($this->andi->id, $this->budi->id);
    }

    private function makeMessage(User $from, User $to, string $body = 'Halo', array $extra = []): Message
    {
        $message = Message::create(array_merge([
            'conversation_id' => $this->conversation->id,
            'sender_id' => $from->id,
            'recipient_id' => $to->id,
            'client_uuid' => (string) Str::uuid(),
            'body' => $body,
            'revision' => 1,
        ], $extra));

        $this->conversation->forceFill(['last_message_id' => $message->id, 'last_message_at' => $message->created_at])->save();

        return $message;
    }

    /** @return array<int,array<string,mixed>> envelope yang dipublish (diisi saat request dijalankan) */
    private function expectPublishes(int $times): \ArrayObject
    {
        config(['chat.realtime_enabled' => true]);
        $captured = new \ArrayObject;

        Redis::shouldReceive('publish')
            ->times($times)
            ->with('chat.messages', Mockery::on(function ($json) use ($captured) {
                $captured[] = json_decode($json, true);

                return true;
            }));

        return $captured;
    }

    // ================= EDIT =================

    public function test_pengirim_mengedit_pesan(): void
    {
        $m = $this->makeMessage($this->andi, $this->budi, 'Teks lama');

        $this->actingAs($this->andi)->patchJson("/api/v1/messages/{$m->id}", ['body' => 'Teks baru'])
            ->assertOk()
            ->assertJsonPath('data.message.body', 'Teks baru')
            ->assertJsonPath('data.message.revision', 2)
            ->assertJsonPath('data.message.status', 'sent');

        $fresh = $m->fresh();
        $this->assertSame('Teks baru', $fresh->body);
        $this->assertNotNull($fresh->edited_at);
        $this->assertSame(2, $fresh->revision);
    }

    public function test_edit_dengan_teks_sama_tidak_mengubah_apa_pun(): void
    {
        $m = $this->makeMessage($this->andi, $this->budi, 'Sama');

        $this->actingAs($this->andi)->patchJson("/api/v1/messages/{$m->id}", ['body' => 'Sama'])
            ->assertOk()
            ->assertJsonPath('data.message.revision', 1)
            ->assertJsonPath('data.message.edited_at', null);
    }

    public function test_edit_ditolak_bukan_pengirim_403_bukan_peserta_404(): void
    {
        $m = $this->makeMessage($this->andi, $this->budi, 'Milik Andi');

        $this->actingAs($this->budi)->patchJson("/api/v1/messages/{$m->id}", ['body' => 'Dibajak'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->actingAs($this->citra)->patchJson("/api/v1/messages/{$m->id}", ['body' => 'Dibajak'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->assertSame('Milik Andi', $m->fresh()->body);
    }

    public function test_edit_pesan_terhapus_dan_melewati_batas_waktu_409(): void
    {
        $deleted = $this->makeMessage($this->andi, $this->budi, 'Terhapus');
        $deleted->delete();

        $this->actingAs($this->andi)->patchJson("/api/v1/messages/{$deleted->id}", ['body' => 'x'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'MESSAGE_DELETED');

        $old = $this->makeMessage($this->andi, $this->budi, 'Sudah lama');
        Message::where('id', $old->id)->update(['created_at' => now()->subMinutes(config('chat.edit_window_minutes') + 1)]);

        $this->actingAs($this->andi)->patchJson("/api/v1/messages/{$old->id}", ['body' => 'x'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'EDIT_WINDOW_EXPIRED');
    }

    public function test_validasi_edit(): void
    {
        $m = $this->makeMessage($this->andi, $this->budi);

        $this->actingAs($this->andi)->patchJson("/api/v1/messages/{$m->id}", ['body' => '   '])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->actingAs($this->andi)->patchJson("/api/v1/messages/{$m->id}", [])->assertStatus(422);
        $this->actingAs($this->andi)->patchJson("/api/v1/messages/{$m->id}", ['body' => str_repeat('a', config('chat.message_max_length') + 1)])
            ->assertStatus(422);
    }

    public function test_edit_memublikasikan_message_updated_ke_kedua_peserta(): void
    {
        $m = $this->makeMessage($this->andi, $this->budi, 'Lama');
        $published = $this->expectPublishes(1);

        $this->actingAs($this->andi)->patchJson("/api/v1/messages/{$m->id}", ['body' => 'Baru'])->assertOk();

        $env = $published[0];
        $this->assertSame('message:updated', $env['event']);
        $this->assertEqualsCanonicalizing([$this->andi->id, $this->budi->id], $env['to']);
        $this->assertNull($env['ack_user_id']);
        $this->assertSame('Baru', $env['data']['message']['body']);
        $this->assertSame(2, $env['data']['message']['revision']);
    }

    // ================= HAPUS =================

    public function test_pengirim_menghapus_pesan_menjadi_tombstone(): void
    {
        $m = $this->makeMessage($this->andi, $this->budi, 'Akan dihapus');
        MessageReaction::create(['message_id' => $m->id, 'user_id' => $this->budi->id, 'emoji' => '👍']);

        $this->actingAs($this->andi)->deleteJson("/api/v1/messages/{$m->id}")
            ->assertOk()
            ->assertJsonPath('data.message.id', $m->id)
            ->assertJsonPath('data.message.body', null)
            ->assertJsonPath('data.message.reactions', [])
            ->assertJsonPath('data.message.revision', 2);

        $this->assertSoftDeleted('messages', ['id' => $m->id]);
        $this->assertDatabaseCount('message_reactions', 0);

        // Tetap muncul di riwayat sebagai tombstone
        $history = $this->actingAs($this->budi)->getJson("/api/v1/conversations/{$this->conversation->id}/messages")
            ->assertJsonPath('data.messages.0.id', $m->id)
            ->assertJsonPath('data.messages.0.body', null);
        $this->assertNotNull($history->json('data.messages.0.deleted_at'));
    }

    public function test_hapus_idempoten_dan_hanya_pengirim(): void
    {
        $m = $this->makeMessage($this->andi, $this->budi);

        $this->actingAs($this->budi)->deleteJson("/api/v1/messages/{$m->id}")->assertStatus(403);
        $this->actingAs($this->citra)->deleteJson("/api/v1/messages/{$m->id}")->assertStatus(404);
        $this->assertNull($m->fresh()->deleted_at);

        $this->actingAs($this->andi)->deleteJson("/api/v1/messages/{$m->id}")->assertOk();
        $this->actingAs($this->andi)->deleteJson("/api/v1/messages/{$m->id}")
            ->assertOk()
            ->assertJsonPath('data.message.revision', 2); // tidak naik lagi
    }

    public function test_hapus_mengurangi_unread_penerima_dan_balasan_menandai_asal_terhapus(): void
    {
        $original = $this->makeMessage($this->andi, $this->budi, 'Pesan asal');
        $reply = $this->makeMessage($this->budi, $this->andi, 'Balasan', ['reply_to_id' => $original->id]);

        $this->actingAs($this->budi)->getJson('/api/v1/unread-counts')->assertJsonPath('data.total', 1);

        $this->actingAs($this->andi)->deleteJson("/api/v1/messages/{$original->id}")->assertOk();

        $this->actingAs($this->budi)->getJson('/api/v1/unread-counts')->assertJsonPath('data.total', 0);

        $this->actingAs($this->andi)->getJson("/api/v1/conversations/{$this->conversation->id}/messages")
            ->assertJsonPath('data.messages.1.id', $reply->id)
            ->assertJsonPath('data.messages.1.reply_to.deleted', true)
            ->assertJsonPath('data.messages.1.reply_to.body_preview', null);
    }

    public function test_hapus_memublikasikan_message_deleted_dan_unread_update(): void
    {
        $m = $this->makeMessage($this->andi, $this->budi);
        $published = $this->expectPublishes(2);

        $this->actingAs($this->andi)->deleteJson("/api/v1/messages/{$m->id}")->assertOk();

        $deleted = $published[0];
        $this->assertSame('message:deleted', $deleted['event']);
        $this->assertEqualsCanonicalizing([$this->andi->id, $this->budi->id], $deleted['to']);
        $this->assertSame($m->id, $deleted['data']['message_id']);
        $this->assertSame($this->conversation->id, $deleted['data']['conversation_id']);
        $this->assertSame(2, $deleted['data']['revision']);
        $this->assertNotEmpty($deleted['data']['deleted_at']);

        $unread = $published[1];
        $this->assertSame('unread:update', $unread['event']);
        $this->assertSame([$this->budi->id], $unread['to']);
        $this->assertSame(0, $unread['data']['unread_count']);
    }

    public function test_hapus_ulang_tidak_memublikasikan_event(): void
    {
        $m = $this->makeMessage($this->andi, $this->budi);
        $m->delete();

        config(['chat.realtime_enabled' => true]);
        Redis::shouldReceive('publish')->never();

        $this->actingAs($this->andi)->deleteJson("/api/v1/messages/{$m->id}")->assertOk();
    }

    // ================= REAKSI =================

    public function test_pasang_reaksi_dan_lihat_di_riwayat(): void
    {
        $m = $this->makeMessage($this->andi, $this->budi);

        $this->actingAs($this->budi)->putJson("/api/v1/messages/{$m->id}/reaction", ['emoji' => '👍'])
            ->assertOk()
            ->assertJsonPath('data.message_id', $m->id)
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.reactions.0.user_id', $this->budi->id)
            ->assertJsonPath('data.reactions.0.emoji', '👍');

        $this->actingAs($this->andi)->getJson("/api/v1/conversations/{$this->conversation->id}/messages")
            ->assertJsonPath('data.messages.0.reactions.0.emoji', '👍')
            ->assertJsonPath('data.messages.0.revision', 2);
    }

    public function test_satu_reaksi_per_user_mengganti_emoji_dan_dua_user_bisa_bereaksi(): void
    {
        $m = $this->makeMessage($this->andi, $this->budi);
        $url = "/api/v1/messages/{$m->id}/reaction";

        $this->actingAs($this->budi)->putJson($url, ['emoji' => '👍'])->assertOk();
        $this->actingAs($this->budi)->putJson($url, ['emoji' => '❤️'])
            ->assertOk()
            ->assertJsonCount(1, 'data.reactions')
            ->assertJsonPath('data.reactions.0.emoji', '❤️')
            ->assertJsonPath('data.revision', 3);

        $this->actingAs($this->andi)->putJson($url, ['emoji' => '😂']) // pengirim boleh bereaksi pada pesannya sendiri
            ->assertOk()
            ->assertJsonCount(2, 'data.reactions');

        $this->assertDatabaseCount('message_reactions', 2);
    }

    public function test_reaksi_yang_sama_tidak_menaikkan_revision(): void
    {
        $m = $this->makeMessage($this->andi, $this->budi);
        $url = "/api/v1/messages/{$m->id}/reaction";

        $this->actingAs($this->budi)->putJson($url, ['emoji' => '🙏'])->assertOk()->assertJsonPath('data.revision', 2);
        $this->actingAs($this->budi)->putJson($url, ['emoji' => '🙏'])->assertOk()->assertJsonPath('data.revision', 2);
    }

    public function test_reaksi_ditolak_emoji_di_luar_allow_list_pesan_terhapus_dan_bukan_peserta(): void
    {
        $m = $this->makeMessage($this->andi, $this->budi);
        $url = "/api/v1/messages/{$m->id}/reaction";

        $this->actingAs($this->budi)->putJson($url, ['emoji' => '🦄'])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->actingAs($this->budi)->putJson($url, ['emoji' => '<script>'])->assertStatus(422);
        $this->actingAs($this->budi)->putJson($url, [])->assertStatus(422);

        $this->actingAs($this->citra)->putJson($url, ['emoji' => '👍'])->assertStatus(404);

        $m->delete();
        $this->actingAs($this->budi)->putJson($url, ['emoji' => '👍'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'MESSAGE_DELETED');
    }

    public function test_hapus_reaksi_idempoten(): void
    {
        $m = $this->makeMessage($this->andi, $this->budi);
        $url = "/api/v1/messages/{$m->id}/reaction";

        $this->actingAs($this->budi)->putJson($url, ['emoji' => '👍'])->assertOk();

        $this->actingAs($this->budi)->deleteJson($url)
            ->assertOk()
            ->assertJsonPath('data.reactions', [])
            ->assertJsonPath('data.revision', 3);

        $this->actingAs($this->budi)->deleteJson($url) // sudah tidak ada
            ->assertOk()
            ->assertJsonPath('data.revision', 3);

        $this->actingAs($this->citra)->deleteJson($url)->assertStatus(404);
    }

    public function test_reaksi_memublikasikan_message_reaction_dengan_seluruh_daftar(): void
    {
        $m = $this->makeMessage($this->andi, $this->budi);
        MessageReaction::create(['message_id' => $m->id, 'user_id' => $this->andi->id, 'emoji' => '😮']);
        $published = $this->expectPublishes(2);

        $this->actingAs($this->budi)->putJson("/api/v1/messages/{$m->id}/reaction", ['emoji' => '👍'])->assertOk();
        $this->actingAs($this->budi)->deleteJson("/api/v1/messages/{$m->id}/reaction")->assertOk();

        $first = $published[0];
        $this->assertSame('message:reaction', $first['event']);
        $this->assertEqualsCanonicalizing([$this->andi->id, $this->budi->id], $first['to']);
        $this->assertSame($m->id, $first['data']['message_id']);
        $this->assertCount(2, $first['data']['reactions']); // seluruh daftar, bukan hanya perubahan
        $this->assertSame(2, $first['data']['revision']);

        $second = $published[1];
        $this->assertCount(1, $second['data']['reactions']);
        $this->assertSame($this->andi->id, $second['data']['reactions'][0]['user_id']);
        $this->assertSame(3, $second['data']['revision']);
    }

    // ================= INTERNAL: participants =================

    private function signedGet(string $uri, ?string $secret = null)
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.', $secret ?? self::INTERNAL_SECRET); // GET: body kosong

        return $this->call('GET', $uri, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_INTERNAL_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_INTERNAL_SIGNATURE' => $signature,
        ]);
    }

    public function test_internal_participants(): void
    {
        $this->signedGet("/internal/v1/conversations/{$this->conversation->id}/participants")
            ->assertOk()
            ->assertJsonPath('data.user_ids', [
                min($this->andi->id, $this->budi->id),
                max($this->andi->id, $this->budi->id),
            ]);

        $this->signedGet('/internal/v1/conversations/99999/participants')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->signedGet("/internal/v1/conversations/{$this->conversation->id}/participants", 'secret-lain')
            ->assertStatus(401);

        $this->getJson("/internal/v1/conversations/{$this->conversation->id}/participants")->assertStatus(401);
    }

    // ================= AKSES =================

    public function test_endpoint_aksi_butuh_login(): void
    {
        $this->patchJson('/api/v1/messages/1', ['body' => 'x'])->assertStatus(401);
        $this->deleteJson('/api/v1/messages/1')->assertStatus(401);
        $this->putJson('/api/v1/messages/1/reaction', ['emoji' => '👍'])->assertStatus(401);
        $this->deleteJson('/api/v1/messages/1/reaction')->assertStatus(401);
    }
}
