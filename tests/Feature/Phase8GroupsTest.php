<?php

namespace Tests\Feature;

use App\Models\ChatNotification;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Uji Fase 8: grup, pesan grup, unread/dibaca, mention (kontrak §17).
 */
class Phase8GroupsTest extends TestCase
{
    use RefreshDatabase;

    private const INTERNAL_SECRET = 'rahasia-internal-test';

    private User $andi;

    private User $budi;

    private User $citra;

    private User $dewi;

    protected function setUp(): void
    {
        parent::setUp();

        config(['chat.realtime_enabled' => false, 'chat.internal_secret' => self::INTERNAL_SECRET]);
        Storage::fake(config('chat.media_disk'));

        $this->andi = User::factory()->create(['name' => 'Andi Pratama']);
        $this->budi = User::factory()->create(['name' => 'Budi Santoso']);
        $this->citra = User::factory()->create(['name' => 'Citra Dewi']);
        $this->dewi = User::factory()->create(['name' => 'Dewi Lestari']);
    }

    // ---------- helper ----------

    /** Andi (pemilik) membuat grup bersama Budi dan Citra. Mengembalikan id grup. */
    private function makeGroup(?array $members = null, string $name = 'Tim Magang'): int
    {
        $members ??= [$this->budi, $this->citra];

        return $this->actingAs($this->andi)->postJson('/api/v1/groups', [
            'name' => $name,
            'member_ids' => array_map(fn (User $u) => $u->id, $members),
        ])->assertCreated()->json('data.conversation.id');
    }

    private function sendGroup(User $from, int $groupId, string $body = 'Halo semua', array $extra = [])
    {
        return $this->actingAs($from)->postJson("/api/v1/conversations/$groupId/messages", array_merge([
            'client_uuid' => (string) Str::uuid(),
            'body' => $body,
        ], $extra));
    }

    private function markRead(User $user, int $groupId, int $upTo)
    {
        return $this->actingAs($user)->postJson("/api/v1/conversations/$groupId/read", ['up_to_message_id' => $upTo]);
    }

    /** Aktifkan realtime dan tangkap SEMUA publish (semua channel). */
    private function captureRedis(): \ArrayObject
    {
        config(['chat.realtime_enabled' => true]);
        $published = new \ArrayObject;

        Redis::shouldReceive('publish')->andReturnUsing(function (string $channel, string $json) use ($published) {
            $published[] = ['channel' => $channel, 'env' => json_decode($json, true)];
        });

        return $published;
    }

    /** @return array<int,array<string,mixed>> envelope dengan nama event ini */
    private function events(\ArrayObject $published, string $event): array
    {
        return array_values(array_map(
            fn ($p) => $p['env'],
            array_filter($published->getArrayCopy(), fn ($p) => $p['env']['event'] === $event),
        ));
    }

    private function conversationIn($response, int $id): ?array
    {
        return collect($response->json('data.conversations'))->firstWhere('id', $id);
    }

    private function pngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    // ================= BUAT GRUP =================

    public function test_buat_grup_pembuat_menjadi_pemilik(): void
    {
        $this->actingAs($this->andi)->postJson('/api/v1/groups', [
            'name' => 'Tim Magang',
            'member_ids' => [$this->budi->id, $this->citra->id],
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.conversation.type', 'group')
            ->assertJsonPath('data.conversation.name', 'Tim Magang')
            ->assertJsonPath('data.conversation.peer', null)
            ->assertJsonPath('data.conversation.owner_id', $this->andi->id)
            ->assertJsonPath('data.conversation.members_count', 3)
            ->assertJsonPath('data.conversation.unread_count', 0)
            ->assertJsonPath('data.conversation.last_message', null)
            ->assertJsonPath('data.conversation.members.0.user.id', $this->andi->id)
            ->assertJsonPath('data.conversation.members.0.role', 'owner')
            ->assertJsonPath('data.conversation.members.1.role', 'member');

        $this->assertDatabaseHas('conversations', ['type' => 'group', 'name' => 'Tim Magang', 'created_by' => $this->andi->id]);
        $this->assertSame(3, ConversationMember::count());
        $this->assertNull(Conversation::first()->user_one_id);
    }

    public function test_payload_tidak_membocorkan_email_anggota(): void
    {
        $response = $this->actingAs($this->andi)->postJson('/api/v1/groups', [
            'name' => 'Tim', 'member_ids' => [$this->budi->id],
        ])->assertCreated();

        $this->assertStringNotContainsString('email', $response->getContent());
    }

    public function test_validasi_buat_grup(): void
    {
        $post = fn (array $payload) => $this->actingAs($this->andi)->postJson('/api/v1/groups', $payload);

        $post(['member_ids' => [$this->budi->id]])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $post(['name' => '', 'member_ids' => [$this->budi->id]])->assertStatus(422);
        $post(['name' => str_repeat('a', 51), 'member_ids' => [$this->budi->id]])->assertStatus(422);
        $post(['name' => 'Tim'])->assertStatus(422);
        $post(['name' => 'Tim', 'member_ids' => []])->assertStatus(422);
        $post(['name' => 'Tim', 'member_ids' => [$this->andi->id, $this->budi->id]])->assertStatus(422); // memasukkan diri sendiri
        $post(['name' => 'Tim', 'member_ids' => [99999]])->assertStatus(422);
        $post(['name' => 'Tim', 'member_ids' => [$this->budi->id, $this->budi->id]])->assertStatus(422);
        $post(['name' => 'Tim', 'member_ids' => range(1000, 1049)])->assertStatus(422); // 50 anggota lain + pembuat > 50

        $this->assertDatabaseCount('conversations', 0);
    }

    // ================= DAFTAR & DETAIL =================

    public function test_grup_muncul_di_daftar_anggota_walau_kosong_dan_tidak_untuk_orang_lain(): void
    {
        $groupId = $this->makeGroup();
        Conversation::between($this->andi->id, $this->budi->id); // direct tanpa pesan: tidak ditampilkan

        $response = $this->actingAs($this->budi)->getJson('/api/v1/conversations')->assertOk();
        $this->assertCount(1, $response->json('data.conversations'));
        $this->assertSame('group', $this->conversationIn($response, $groupId)['type']);

        $this->actingAs($this->dewi)->getJson('/api/v1/conversations')
            ->assertOk()->assertJsonCount(0, 'data.conversations');
    }

    public function test_detail_percakapan_untuk_grup_dan_direct(): void
    {
        $groupId = $this->makeGroup();
        $direct = Conversation::between($this->andi->id, $this->budi->id);

        $this->actingAs($this->citra)->getJson("/api/v1/conversations/$groupId")
            ->assertOk()
            ->assertJsonPath('data.conversation.type', 'group')
            ->assertJsonPath('data.conversation.members_count', 3);

        $this->actingAs($this->dewi)->getJson("/api/v1/conversations/$groupId")
            ->assertStatus(404)->assertJsonPath('error.code', 'NOT_FOUND');

        $this->actingAs($this->andi)->getJson("/api/v1/conversations/{$direct->id}")
            ->assertOk()
            ->assertJsonPath('data.conversation.type', 'direct')
            ->assertJsonPath('data.conversation.name', null)
            ->assertJsonPath('data.conversation.peer.id', $this->budi->id);
    }

    // ================= KELOLA GRUP =================

    public function test_hanya_pemilik_boleh_ganti_nama(): void
    {
        $groupId = $this->makeGroup();

        $this->actingAs($this->budi)->patchJson("/api/v1/groups/$groupId", ['name' => 'Dibajak'])
            ->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        $this->actingAs($this->dewi)->patchJson("/api/v1/groups/$groupId", ['name' => 'Dibajak'])->assertStatus(404);

        $this->actingAs($this->andi)->patchJson("/api/v1/groups/$groupId", ['name' => 'Tim Baru'])
            ->assertOk()->assertJsonPath('data.conversation.name', 'Tim Baru');
        $this->assertSame('Tim Baru', Conversation::find($groupId)->name);

        $this->actingAs($this->andi)->patchJson("/api/v1/groups/$groupId", ['name' => ''])->assertStatus(422);
    }

    public function test_ganti_nama_dan_perubahan_anggota_mempublish_conversation_updated(): void
    {
        $groupId = $this->makeGroup();
        $published = $this->captureRedis();

        $this->actingAs($this->andi)->patchJson("/api/v1/groups/$groupId", ['name' => 'Nama Lain'])->assertOk();
        $this->actingAs($this->andi)->postJson("/api/v1/groups/$groupId/members", ['user_ids' => [$this->dewi->id]])->assertOk();

        $updated = $this->events($published, 'conversation:updated');
        $this->assertCount(2, $updated);

        $this->assertSame('renamed', $updated[0]['data']['reason']);
        $this->assertSame($groupId, $updated[0]['data']['conversation_id']);
        $this->assertEqualsCanonicalizing([$this->andi->id, $this->budi->id, $this->citra->id], $updated[0]['to']);

        $this->assertSame('members_added', $updated[1]['data']['reason']);
        $this->assertContains($this->dewi->id, $updated[1]['to']); // anggota baru ikut diberi tahu
        $this->assertNull($updated[1]['ack_user_id']);
    }

    public function test_tambah_anggota_hanya_pemilik_dan_yang_sudah_ada_dilewati(): void
    {
        $groupId = $this->makeGroup();

        $this->actingAs($this->budi)->postJson("/api/v1/groups/$groupId/members", ['user_ids' => [$this->dewi->id]])->assertStatus(403);
        $this->actingAs($this->dewi)->postJson("/api/v1/groups/$groupId/members", ['user_ids' => [$this->dewi->id]])->assertStatus(404);

        $this->actingAs($this->andi)->postJson("/api/v1/groups/$groupId/members", ['user_ids' => [$this->dewi->id, $this->budi->id]])
            ->assertOk()
            ->assertJsonPath('data.conversation.members_count', 4); // Budi sudah anggota: tidak dobel

        $this->assertSame(1, ConversationMember::where('user_id', $this->budi->id)->count());
        $this->actingAs($this->dewi)->getJson("/api/v1/conversations/$groupId")->assertOk();

        $this->actingAs($this->andi)->postJson("/api/v1/groups/$groupId/members", ['user_ids' => []])->assertStatus(422);
        $this->actingAs($this->andi)->postJson("/api/v1/groups/$groupId/members", ['user_ids' => [99999]])->assertStatus(422);
    }

    public function test_batas_50_anggota(): void
    {
        $others = User::factory()->count(48)->create(); // pemilik + 48 = 49 anggota
        $groupId = $this->makeGroup($others->all());

        // 49 + 2 = 51 > 50 -> ditolak seluruhnya
        $this->actingAs($this->andi)->postJson("/api/v1/groups/$groupId/members", ['user_ids' => [$this->budi->id, $this->citra->id]])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertSame(49, Conversation::find($groupId)->activeMembers()->count());

        // 49 + 1 = 50 -> boleh
        $this->actingAs($this->andi)->postJson("/api/v1/groups/$groupId/members", ['user_ids' => [$this->budi->id]])
            ->assertOk()->assertJsonPath('data.conversation.members_count', 50);
    }

    public function test_pemilik_mengeluarkan_anggota(): void
    {
        $groupId = $this->makeGroup();
        $this->sendGroup($this->andi, $groupId, 'Sebelum dikeluarkan')->assertCreated();

        $this->actingAs($this->citra)->deleteJson("/api/v1/groups/$groupId/members/{$this->budi->id}")->assertStatus(403);
        $this->actingAs($this->andi)->deleteJson("/api/v1/groups/$groupId/members/{$this->dewi->id}")->assertStatus(404); // bukan anggota
        $this->actingAs($this->andi)->deleteJson("/api/v1/groups/$groupId/members/{$this->andi->id}")->assertStatus(422);    // diri sendiri

        $this->actingAs($this->andi)->deleteJson("/api/v1/groups/$groupId/members/{$this->citra->id}")
            ->assertOk()->assertJsonPath('data.conversation.members_count', 2);

        // Citra kehilangan seluruh akses
        $this->actingAs($this->citra)->getJson("/api/v1/conversations/$groupId")->assertStatus(404);
        $this->actingAs($this->citra)->getJson("/api/v1/conversations/$groupId/messages")->assertStatus(404);
        $this->sendGroup($this->citra, $groupId, 'Menyusup')->assertStatus(404);
        $this->actingAs($this->citra)->getJson('/api/v1/conversations')->assertJsonCount(0, 'data.conversations');
    }

    public function test_pengeluaran_mempublish_removed_ke_yang_dikeluarkan_dan_updated_ke_sisanya(): void
    {
        $groupId = $this->makeGroup();
        $published = $this->captureRedis();

        $this->actingAs($this->andi)->deleteJson("/api/v1/groups/$groupId/members/{$this->citra->id}")->assertOk();

        $removed = $this->events($published, 'conversation:removed');
        $this->assertCount(1, $removed);
        $this->assertSame([$this->citra->id], $removed[0]['to']);
        $this->assertSame($groupId, $removed[0]['data']['conversation_id']);

        $updated = $this->events($published, 'conversation:updated');
        $this->assertSame('member_removed', $updated[0]['data']['reason']);
        $this->assertEqualsCanonicalizing([$this->andi->id, $this->budi->id], $updated[0]['to']); // tanpa Citra
    }

    public function test_anggota_keluar_dan_kepemilikan_berpindah_saat_pemilik_keluar(): void
    {
        $groupId = $this->makeGroup();

        $this->actingAs($this->budi)->postJson("/api/v1/groups/$groupId/leave")
            ->assertOk()->assertJsonPath('data', null);
        $this->actingAs($this->budi)->getJson("/api/v1/conversations/$groupId")->assertStatus(404);

        // Pemilik keluar -> Citra (anggota aktif terlama yang tersisa) menjadi pemilik
        $this->actingAs($this->andi)->postJson("/api/v1/groups/$groupId/leave")->assertOk();

        $this->actingAs($this->citra)->getJson("/api/v1/conversations/$groupId")
            ->assertOk()
            ->assertJsonPath('data.conversation.owner_id', $this->citra->id)
            ->assertJsonPath('data.conversation.members_count', 1);

        $this->actingAs($this->citra)->patchJson("/api/v1/groups/$groupId", ['name' => 'Milik Citra'])->assertOk();
        $this->actingAs($this->dewi)->postJson("/api/v1/groups/$groupId/leave")->assertStatus(404);
    }

    public function test_anggota_yang_pernah_keluar_bisa_ditambahkan_lagi_dengan_kursor_baru(): void
    {
        $groupId = $this->makeGroup();
        $this->actingAs($this->budi)->postJson("/api/v1/groups/$groupId/leave")->assertOk();

        $m1 = $this->sendGroup($this->andi, $groupId, 'Satu')->json('data.message.id');
        $m2 = $this->sendGroup($this->andi, $groupId, 'Dua')->json('data.message.id');

        $this->actingAs($this->andi)->postJson("/api/v1/groups/$groupId/members", ['user_ids' => [$this->budi->id]])->assertOk();

        $this->assertSame(1, ConversationMember::where('user_id', $this->budi->id)->count()); // baris dipakai ulang
        $this->assertNull(ConversationMember::where('user_id', $this->budi->id)->first()->left_at);
        $this->assertSame($m2, ConversationMember::where('user_id', $this->budi->id)->first()->last_read_message_id);
        $this->assertGreaterThan($m1, $m2);
    }

    // ================= PESAN GRUP =================

    public function test_kirim_pesan_grup(): void
    {
        $groupId = $this->makeGroup();

        $this->sendGroup($this->andi, $groupId, 'Halo semua')
            ->assertCreated()
            ->assertJsonPath('data.message.body', 'Halo semua')
            ->assertJsonPath('data.message.conversation_id', $groupId)
            ->assertJsonPath('data.message.sender_id', $this->andi->id)
            ->assertJsonPath('data.message.recipient_id', null)
            ->assertJsonPath('data.message.status', 'sent')
            ->assertJsonPath('data.message.read_by_count', 0)
            ->assertJsonPath('data.message.mentions', []);

        $conversation = Conversation::find($groupId);
        $this->assertNotNull($conversation->last_message_id);

        // Pesan biasa di grup TIDAK membuat baris notifikasi (keputusan #7)
        $this->assertDatabaseCount('notifications', 0);

        // Anggota lain membacanya lewat riwayat
        $this->actingAs($this->budi)->getJson("/api/v1/conversations/$groupId/messages")
            ->assertOk()
            ->assertJsonPath('data.messages.0.body', 'Halo semua');
    }

    public function test_bukan_anggota_tidak_bisa_mengirim_atau_membaca(): void
    {
        $groupId = $this->makeGroup();
        $this->sendGroup($this->andi, $groupId, 'Rahasia')->assertCreated();

        $this->sendGroup($this->dewi, $groupId, 'Menyusup')->assertStatus(404);
        $this->actingAs($this->dewi)->getJson("/api/v1/conversations/$groupId/messages")->assertStatus(404);
        $this->markRead($this->dewi, $groupId, 1)->assertStatus(404);

        $this->assertDatabaseMissing('messages', ['body' => 'Menyusup']);
    }

    public function test_idempoten_dengan_client_uuid_pada_grup(): void
    {
        $groupId = $this->makeGroup();
        $uuid = (string) Str::uuid();

        $first = $this->sendGroup($this->andi, $groupId, 'Sekali', ['client_uuid' => $uuid])->assertCreated();
        $second = $this->sendGroup($this->andi, $groupId, 'Sekali', ['client_uuid' => $uuid])->assertOk();

        $this->assertSame($first->json('data.message.id'), $second->json('data.message.id'));
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_reply_gaya_whatsapp_ditolak_di_grup_dan_mention_ditolak_di_direct(): void
    {
        $groupId = $this->makeGroup();
        $first = $this->sendGroup($this->andi, $groupId, 'Pesan')->json('data.message.id');

        $this->sendGroup($this->budi, $groupId, 'Balasan', ['reply_to_id' => $first])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $direct = Conversation::between($this->andi->id, $this->budi->id);
        $this->actingAs($this->andi)->postJson("/api/v1/conversations/{$direct->id}/messages", [
            'client_uuid' => (string) Str::uuid(), 'body' => 'Halo', 'mention_ids' => [$this->budi->id],
        ])->assertStatus(422);
    }

    public function test_chat_pribadi_tetap_berfungsi_bersama_grup(): void
    {
        $this->makeGroup();
        $direct = Conversation::between($this->andi->id, $this->budi->id);
        $first = $this->actingAs($this->andi)->postJson("/api/v1/conversations/{$direct->id}/messages", [
            'client_uuid' => (string) Str::uuid(), 'body' => 'Pribadi',
        ])->assertCreated()
            ->assertJsonPath('data.message.recipient_id', $this->budi->id)
            ->assertJsonPath('data.message.read_by_count', null)
            ->assertJsonPath('data.message.mentions', []);

        // reply gaya WhatsApp pada direct tidak berubah
        $this->actingAs($this->budi)->postJson("/api/v1/conversations/{$direct->id}/messages", [
            'client_uuid' => (string) Str::uuid(), 'body' => 'Balas', 'reply_to_id' => $first->json('data.message.id'),
        ])->assertCreated()->assertJsonPath('data.message.reply_to.id', $first->json('data.message.id'));

        $this->assertDatabaseHas('notifications', ['user_id' => $this->budi->id, 'type' => 'chat.message']);
    }

    public function test_pesan_grup_mempublish_ke_semua_anggota_tanpa_ack_dan_unread_per_anggota(): void
    {
        $groupId = $this->makeGroup();
        $published = $this->captureRedis();

        $this->sendGroup($this->andi, $groupId, 'Halo')->assertCreated();

        $new = $this->events($published, 'message:new');
        $this->assertCount(1, $new);
        $this->assertEqualsCanonicalizing([$this->andi->id, $this->budi->id, $this->citra->id], $new[0]['to']);
        $this->assertNull($new[0]['ack_user_id']); // grup: tanpa status delivered
        $this->assertSame('Halo', $new[0]['data']['message']['body']);

        $unread = $this->events($published, 'unread:update');
        $this->assertCount(2, $unread); // Budi dan Citra; pengirim tidak
        foreach ($unread as $env) {
            $this->assertCount(1, $env['to']);
            $this->assertNotSame($this->andi->id, $env['to'][0]);
            $this->assertSame($groupId, $env['data']['conversation_id']);
            $this->assertSame(1, $env['data']['unread_count']);
            $this->assertSame(1, $env['data']['total_unread']);
        }
    }

    // ================= UNREAD & DIBACA =================

    public function test_unread_grup_dihitung_dari_kursor_dan_digabung_dengan_direct(): void
    {
        $groupId = $this->makeGroup();
        foreach (['Satu', 'Dua', 'Tiga'] as $body) {
            $this->sendGroup($this->andi, $groupId, $body)->assertCreated();
        }

        // Pesan direct dari Citra ke Budi
        $direct = Conversation::between($this->citra->id, $this->budi->id);
        $this->actingAs($this->citra)->postJson("/api/v1/conversations/{$direct->id}/messages", [
            'client_uuid' => (string) Str::uuid(), 'body' => 'Pribadi',
        ])->assertCreated();

        $conversations = $this->actingAs($this->budi)->getJson('/api/v1/conversations')->assertOk();
        $this->assertSame(3, $this->conversationIn($conversations, $groupId)['unread_count']);
        $this->assertSame(1, $this->conversationIn($conversations, $direct->id)['unread_count']);

        $counts = $this->actingAs($this->budi)->getJson('/api/v1/unread-counts')->assertOk()->assertJsonPath('data.total', 4);
        $this->assertEqualsCanonicalizing(
            [['conversation_id' => $groupId, 'unread_count' => 3], ['conversation_id' => $direct->id, 'unread_count' => 1]],
            $counts->json('data.by_conversation'),
        );

        // Pengirim tidak menghitung pesannya sendiri
        $this->actingAs($this->andi)->getJson('/api/v1/unread-counts')->assertJsonPath('data.total', 0);
    }

    public function test_tandai_dibaca_menggeser_kursor_idempoten_dan_tidak_mundur(): void
    {
        $groupId = $this->makeGroup();
        $m1 = $this->sendGroup($this->andi, $groupId, 'Satu')->json('data.message.id');
        $m2 = $this->sendGroup($this->andi, $groupId, 'Dua')->json('data.message.id');
        $m3 = $this->sendGroup($this->andi, $groupId, 'Tiga')->json('data.message.id');

        $this->markRead($this->budi, $groupId, $m2)
            ->assertOk()
            ->assertJsonPath('data.read_count', 2)
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.total_unread', 1);
        $this->assertNotNull($this->markRead($this->budi, $groupId, $m3)->json('data.read_at'));

        $member = ConversationMember::where('user_id', $this->budi->id)->first();
        $this->assertSame($m3, $member->last_read_message_id);

        // Idempoten dan tidak bisa mundur
        $this->markRead($this->budi, $groupId, $m3)->assertOk()->assertJsonPath('data.read_count', 0)->assertJsonPath('data.read_at', null);
        $this->markRead($this->budi, $groupId, $m1)->assertOk()->assertJsonPath('data.read_count', 0)->assertJsonPath('data.unread_count', 0);
        $this->assertSame($m3, $member->fresh()->last_read_message_id);

        // up_to dari percakapan lain ditolak
        $other = Conversation::between($this->andi->id, $this->budi->id);
        $foreign = Message::create([
            'conversation_id' => $other->id, 'sender_id' => $this->andi->id, 'recipient_id' => $this->budi->id,
            'client_uuid' => (string) Str::uuid(), 'body' => 'x', 'revision' => 1,
        ]);
        $this->markRead($this->budi, $groupId, $foreign->id)->assertStatus(422);
    }

    public function test_tandai_dibaca_mempublish_group_read_ke_anggota_lain_dan_unread_ke_pembaca(): void
    {
        $groupId = $this->makeGroup();
        $m1 = $this->sendGroup($this->andi, $groupId, 'Satu')->json('data.message.id');
        $m2 = $this->sendGroup($this->andi, $groupId, 'Dua')->json('data.message.id');

        $published = $this->captureRedis();
        $this->markRead($this->budi, $groupId, $m2)->assertOk();

        $read = $this->events($published, 'group:read');
        $this->assertCount(1, $read);
        $this->assertEqualsCanonicalizing([$this->andi->id, $this->citra->id], $read[0]['to']); // bukan Budi
        $this->assertSame($this->budi->id, $read[0]['data']['reader_id']);
        $this->assertSame(0, $read[0]['data']['from_message_id']);
        $this->assertSame($m2, $read[0]['data']['up_to_message_id']);

        $unread = $this->events($published, 'unread:update');
        $this->assertCount(1, $unread);
        $this->assertSame([$this->budi->id], $unread[0]['to']);
        $this->assertSame(0, $unread[0]['data']['unread_count']);

        // Panggilan ulang tidak memublikasikan apa pun
        $before = count($published);
        $this->markRead($this->budi, $groupId, $m2)->assertOk();
        $this->assertSame($before, count($published));

        $this->markRead($this->citra, $groupId, $m1)->assertOk();
        $second = $this->events($published, 'group:read')[1];
        $this->assertSame(0, $second['data']['from_message_id']);
        $this->assertSame($m1, $second['data']['up_to_message_id']);
        $this->assertNotContains($this->citra->id, $second['to']);
    }

    public function test_dibaca_oleh_dihitung_dari_kursor_anggota(): void
    {
        $groupId = $this->makeGroup();
        $m1 = $this->sendGroup($this->andi, $groupId, 'Satu')->json('data.message.id');
        $m2 = $this->sendGroup($this->andi, $groupId, 'Dua')->json('data.message.id');
        $this->sendGroup($this->andi, $groupId, 'Tiga')->assertCreated();

        $this->markRead($this->budi, $groupId, $m2)->assertOk();
        $this->markRead($this->citra, $groupId, $m1)->assertOk();

        $messages = $this->actingAs($this->andi)->getJson("/api/v1/conversations/$groupId/messages")->assertOk();
        $this->assertSame([2, 1, 0], array_column($messages->json('data.messages'), 'read_by_count'));

        // Pengirim sendiri tidak ikut dihitung walau kursornya jauh di depan
        $this->markRead($this->andi, $groupId, $m2)->assertOk();
        $messages = $this->actingAs($this->andi)->getJson("/api/v1/conversations/$groupId/messages");
        $this->assertSame([2, 1, 0], array_column($messages->json('data.messages'), 'read_by_count'));
    }

    public function test_anggota_baru_melihat_seluruh_riwayat_tetapi_unread_mulai_dari_nol(): void
    {
        $groupId = $this->makeGroup([$this->budi]);
        $this->sendGroup($this->andi, $groupId, 'Satu')->assertCreated();
        $this->sendGroup($this->andi, $groupId, 'Dua')->assertCreated();

        $this->actingAs($this->andi)->postJson("/api/v1/groups/$groupId/members", ['user_ids' => [$this->dewi->id]])->assertOk();

        $this->actingAs($this->dewi)->getJson("/api/v1/conversations/$groupId")
            ->assertOk()->assertJsonPath('data.conversation.unread_count', 0);
        $this->actingAs($this->dewi)->getJson("/api/v1/conversations/$groupId/messages")
            ->assertOk()->assertJsonCount(2, 'data.messages');

        $this->sendGroup($this->andi, $groupId, 'Tiga')->assertCreated();
        $this->actingAs($this->dewi)->getJson("/api/v1/conversations/$groupId")->assertJsonPath('data.conversation.unread_count', 1);
    }

    // ================= MENTION =================

    public function test_mention_membuat_notifikasi_hanya_untuk_yang_disebut(): void
    {
        $groupId = $this->makeGroup();

        $response = $this->sendGroup($this->andi, $groupId, 'Halo @Budi, cek ini', ['mention_ids' => [$this->budi->id]])
            ->assertCreated()
            ->assertJsonPath('data.message.mentions', [$this->budi->id]);

        $this->assertDatabaseCount('notifications', 1);
        $n = ChatNotification::first();
        $this->assertSame($this->budi->id, $n->user_id);
        $this->assertSame('chat.mention', $n->type);
        $this->assertSame('Andi Pratama menyebut Anda di Tim Magang', $n->title);
        $this->assertSame('Halo @Budi, cek ini', $n->body);
        $this->assertSame($groupId, $n->data['conversation_id']);
        $this->assertSame($response->json('data.message.id'), $n->data['message_id']);
        $this->assertSame('/chat?conversation='.$groupId, $n->data['url']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $this->citra->id]);
        $this->assertDatabaseHas('message_mentions', ['message_id' => $response->json('data.message.id'), 'user_id' => $this->budi->id]);

        // Mention tampil di riwayat
        $this->actingAs($this->citra)->getJson("/api/v1/conversations/$groupId/messages")
            ->assertJsonPath('data.messages.0.mentions', [$this->budi->id]);
    }

    public function test_mention_mempublish_notification_new_dengan_ack_ke_yang_disebut(): void
    {
        $groupId = $this->makeGroup();
        $published = $this->captureRedis();

        $this->sendGroup($this->andi, $groupId, 'Halo @Budi dan @Citra', ['mention_ids' => [$this->budi->id, $this->citra->id]])->assertCreated();

        $notifications = $this->events($published, 'notification:new');
        $this->assertCount(2, $notifications);

        foreach ($notifications as $env) {
            $this->assertCount(1, $env['to']);
            $this->assertSame($env['to'][0], $env['ack_user_id']);
            $this->assertSame('chat.mention', $env['data']['notification']['type']);
            $this->assertSame(1, $env['data']['unread_count']);
        }

        foreach ($published as $p) { // semua notifikasi lewat channel notifikasi
            if ($p['env']['event'] === 'notification:new') {
                $this->assertSame('chat.notifications', $p['channel']);
            }
        }
    }

    public function test_mention_tidak_valid_ditolak(): void
    {
        $groupId = $this->makeGroup();

        foreach ([
            [$this->andi->id],              // diri sendiri
            [$this->dewi->id],              // bukan anggota
            [99999],                        // tidak ada
            [$this->budi->id, $this->budi->id], // duplikat
        ] as $ids) {
            $this->sendGroup($this->andi, $groupId, 'Halo', ['mention_ids' => $ids])
                ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        }

        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_mention_terbaca_saat_pesan_dibaca(): void
    {
        $groupId = $this->makeGroup();
        $m1 = $this->sendGroup($this->andi, $groupId, 'Halo @Budi', ['mention_ids' => [$this->budi->id]])->json('data.message.id');
        $m2 = $this->sendGroup($this->andi, $groupId, 'Lagi @Budi', ['mention_ids' => [$this->budi->id]])->json('data.message.id');

        $published = $this->captureRedis();
        $this->markRead($this->budi, $groupId, $m1)->assertOk();

        $this->assertSame(1, ChatNotification::whereNull('read_at')->count()); // yang kedua belum
        $read = $this->events($published, 'notification:read');
        $this->assertCount(1, $read);
        $this->assertSame([$this->budi->id], $read[0]['to']);
        $this->assertSame(1, $read[0]['data']['unread_count']);

        $this->markRead($this->budi, $groupId, $m2)->assertOk();
        $this->assertSame(0, ChatNotification::whereNull('read_at')->count());
    }

    // ================= FITUR LAMA DI DALAM GRUP =================

    public function test_reaksi_edit_dan_hapus_di_grup_dikirim_ke_semua_anggota(): void
    {
        $groupId = $this->makeGroup();
        $messageId = $this->sendGroup($this->andi, $groupId, 'Awal')->json('data.message.id');
        $members = [$this->andi->id, $this->budi->id, $this->citra->id];

        $published = $this->captureRedis();

        $this->actingAs($this->budi)->putJson("/api/v1/messages/$messageId/reaction", ['emoji' => '👍'])->assertOk();
        $reaction = $this->events($published, 'message:reaction');
        $this->assertCount(1, $reaction);
        $this->assertEqualsCanonicalizing($members, $reaction[0]['to']);

        $this->actingAs($this->andi)->patchJson("/api/v1/messages/$messageId", ['body' => 'Diedit'])
            ->assertOk()->assertJsonPath('data.message.body', 'Diedit')->assertJsonPath('data.message.read_by_count', 0);
        $this->actingAs($this->budi)->patchJson("/api/v1/messages/$messageId", ['body' => 'Dibajak'])->assertStatus(403);
        $updated = $this->events($published, 'message:updated');
        $this->assertCount(1, $updated);
        $this->assertEqualsCanonicalizing($members, $updated[0]['to']);

        $this->actingAs($this->budi)->deleteJson("/api/v1/messages/$messageId")->assertStatus(403);
        $this->actingAs($this->andi)->deleteJson("/api/v1/messages/$messageId")->assertOk()->assertJsonPath('data.message.body', null);
        $deleted = $this->events($published, 'message:deleted');
        $this->assertCount(1, $deleted);
        $this->assertEqualsCanonicalizing($members, $deleted[0]['to']);

        // Unread anggota dikoreksi setelah pesan dihapus: satu event per anggota selain pengirim
        $unread = array_values(array_filter($this->events($published, 'unread:update'), fn ($e) => $e['data']['unread_count'] === 0));
        $this->assertCount(2, $unread);
    }

    public function test_dua_anggota_bisa_bereaksi_pada_pesan_grup(): void
    {
        $groupId = $this->makeGroup();
        $messageId = $this->sendGroup($this->andi, $groupId)->json('data.message.id');

        $this->actingAs($this->budi)->putJson("/api/v1/messages/$messageId/reaction", ['emoji' => '👍'])->assertOk();
        $this->actingAs($this->citra)->putJson("/api/v1/messages/$messageId/reaction", ['emoji' => '❤️'])
            ->assertOk()->assertJsonCount(2, 'data.reactions');

        $this->actingAs($this->dewi)->putJson("/api/v1/messages/$messageId/reaction", ['emoji' => '👍'])->assertStatus(404);
    }

    public function test_lampiran_pada_pesan_grup_hanya_bisa_diakses_anggota_aktif(): void
    {
        $groupId = $this->makeGroup();

        $attachmentId = $this->actingAs($this->andi)->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/attachments', ['file' => UploadedFile::fake()->createWithContent('foto.png', $this->pngBytes())])
            ->assertCreated()->json('data.attachment.id');

        $this->sendGroup($this->andi, $groupId, 'Lihat', ['attachment_ids' => [$attachmentId]])
            ->assertCreated()->assertJsonCount(1, 'data.message.attachments');

        $this->actingAs($this->budi)->get("/api/v1/attachments/$attachmentId/content")->assertOk();
        $this->actingAs($this->dewi)->get("/api/v1/attachments/$attachmentId/content")->assertStatus(404);

        $this->actingAs($this->budi)->postJson("/api/v1/groups/$groupId/leave")->assertOk();
        $this->actingAs($this->budi)->get("/api/v1/attachments/$attachmentId/content")->assertStatus(404);
    }

    // ================= ENDPOINT INTERNAL =================

    private function signedGet(string $uri)
    {
        $timestamp = time();

        return $this->call('GET', $uri, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_INTERNAL_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_INTERNAL_SIGNATURE' => hash_hmac('sha256', $timestamp.'.', self::INTERNAL_SECRET),
        ]);
    }

    public function test_internal_participants_grup_hanya_anggota_aktif(): void
    {
        $groupId = $this->makeGroup();

        $response = $this->signedGet("/internal/v1/conversations/$groupId/participants")->assertOk()->assertJsonPath('data.type', 'group');
        $this->assertEqualsCanonicalizing(
            [$this->andi->id, $this->budi->id, $this->citra->id],
            $response->json('data.user_ids'),
        );

        $this->actingAs($this->andi)->deleteJson("/api/v1/groups/$groupId/members/{$this->citra->id}")->assertOk();

        $after = $this->signedGet("/internal/v1/conversations/$groupId/participants");
        $this->assertEqualsCanonicalizing([$this->andi->id, $this->budi->id], $after->json('data.user_ids'));

        $direct = Conversation::between($this->andi->id, $this->budi->id);
        $this->signedGet("/internal/v1/conversations/{$direct->id}/participants")
            ->assertOk()
            ->assertJsonPath('data.type', 'direct')
            ->assertJsonPath('data.user_ids', [min($this->andi->id, $this->budi->id), max($this->andi->id, $this->budi->id)]);
    }

    // ================= AKSES =================

    public function test_endpoint_grup_butuh_login(): void
    {
        $this->postJson('/api/v1/groups', [])->assertStatus(401);
        $this->patchJson('/api/v1/groups/1', [])->assertStatus(401);
        $this->postJson('/api/v1/groups/1/members', [])->assertStatus(401);
        $this->deleteJson('/api/v1/groups/1/members/2')->assertStatus(401);
        $this->postJson('/api/v1/groups/1/leave')->assertStatus(401);
        $this->getJson('/api/v1/conversations/1')->assertStatus(401);
    }

    public function test_group_endpoint_tidak_berlaku_untuk_percakapan_direct(): void
    {
        $direct = Conversation::between($this->andi->id, $this->budi->id);

        $this->actingAs($this->andi)->patchJson("/api/v1/groups/{$direct->id}", ['name' => 'X'])->assertStatus(404);
        $this->actingAs($this->andi)->postJson("/api/v1/groups/{$direct->id}/members", ['user_ids' => [$this->citra->id]])->assertStatus(404);
        $this->actingAs($this->andi)->postJson("/api/v1/groups/{$direct->id}/leave")->assertStatus(404);
    }
}
