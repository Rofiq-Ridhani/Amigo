<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Uji Fase 6: upload, validasi isi file, lampiran pada pesan, akses, pembersihan (kontrak §D.6, §G).
 */
class Phase6MediaTest extends TestCase
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
        Storage::fake(config('chat.media_disk'));

        $this->andi = User::factory()->create(['name' => 'Andi Pratama']);
        $this->budi = User::factory()->create(['name' => 'Budi Santoso']);
        $this->citra = User::factory()->create(['name' => 'Citra Dewi']);
        $this->conversation = Conversation::between($this->andi->id, $this->budi->id);
    }

    // ---------- helper ----------

    private function pngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    private function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";
    }

    private function upload(User $user, string $name, string $content)
    {
        return $this->actingAs($user)
            ->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/attachments', ['file' => UploadedFile::fake()->createWithContent($name, $content)]);
    }

    /** Unggah PNG valid, kembalikan id. */
    private function uploadPng(User $user, string $name = 'foto.png'): int
    {
        return $this->upload($user, $name, $this->pngBytes())->assertCreated()->json('data.attachment.id');
    }

    /** Baris lampiran pending langsung ke database (tanpa upload). */
    private function pendingAttachment(User $owner, array $extra = []): Attachment
    {
        $path = 'attachments/2026/10/'.Str::uuid().'.png';
        Storage::disk(config('chat.media_disk'))->put($path, $this->pngBytes());

        return Attachment::create(array_merge([
            'message_id' => null,
            'uploader_id' => $owner->id,
            'disk' => config('chat.media_disk'),
            'path' => $path,
            'original_name' => 'foto.png',
            'mime_type' => 'image/png',
            'kind' => 'image',
            'size_bytes' => strlen($this->pngBytes()),
            'width' => 1,
            'height' => 1,
        ], $extra));
    }

    private function sendWith(User $from, array $payload)
    {
        return $this->actingAs($from)->postJson(
            "/api/v1/conversations/{$this->conversation->id}/messages",
            array_merge(['client_uuid' => (string) Str::uuid()], $payload),
        );
    }

    // ================= UPLOAD =================

    public function test_upload_gambar_berhasil_dan_tersimpan_di_disk_privat(): void
    {
        $response = $this->upload($this->andi, 'foto.png', $this->pngBytes())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.attachment.kind', 'image')
            ->assertJsonPath('data.attachment.mime_type', 'image/png')
            ->assertJsonPath('data.attachment.original_name', 'foto.png')
            ->assertJsonPath('data.attachment.width', 1)
            ->assertJsonPath('data.attachment.height', 1)
            ->assertJsonPath('data.attachment.message_id', null)
            ->assertJsonPath('data.attachment.size_bytes', strlen($this->pngBytes()));

        $id = $response->json('data.attachment.id');
        $this->assertSame("/api/v1/attachments/$id/content", $response->json('data.attachment.url'));
        $this->assertSame("/api/v1/attachments/$id/content?download=1", $response->json('data.attachment.download_url'));

        $row = Attachment::findOrFail($id);
        $this->assertSame($this->andi->id, $row->uploader_id);
        $this->assertStringStartsWith('attachments/'.now()->format('Y/m').'/', $row->path);
        $this->assertStringEndsWith('.png', $row->path);
        $this->assertStringNotContainsString('foto', $row->path); // nama acak, bukan nama dari client
        Storage::disk(config('chat.media_disk'))->assertExists($row->path);
    }

    public function test_ekstensi_dan_content_type_dari_client_tidak_dipercaya(): void
    {
        // Isi PNG tetapi bernama .exe -> diterima sebagai PNG, disimpan sebagai .png
        $response = $this->upload($this->andi, 'evil.exe', $this->pngBytes())->assertCreated();

        $row = Attachment::findOrFail($response->json('data.attachment.id'));
        $this->assertSame('image/png', $row->mime_type);
        $this->assertStringEndsWith('.png', $row->path);
        $this->assertSame('evil.exe', $row->original_name); // hanya untuk tampilan
    }

    public function test_jenis_berbahaya_ditolak_415_dan_tidak_tersimpan(): void
    {
        $cases = [
            'executable menyamar jadi png' => ['foto.png', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00".str_repeat("\0", 100)],
            'html menyamar jadi png' => ['foto.png', '<html><script>alert(1)</script></html>'],
            'svg (XSS)' => ['gambar.svg', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'svg menyamar jadi png' => ['gambar.png', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'php' => ['shell.php', '<?php system($_GET["c"]);'],
            'shell script' => ['run.sh', "#!/bin/sh\nrm -rf /\n"],
        ];

        foreach ($cases as $label => [$name, $content]) {
            $this->upload($this->andi, $name, $content)
                ->assertStatus(415)
                ->assertJsonPath('success', false)
                ->assertJsonPath('error.code', 'UNSUPPORTED_MEDIA_TYPE');
        }

        $this->assertDatabaseCount('attachments', 0);
        $this->assertSame([], Storage::disk(config('chat.media_disk'))->allFiles());
    }

    public function test_file_terlalu_besar_ditolak_413(): void
    {
        $limitMb = config('chat.limits.max_size_mb.image');
        $big = $this->pngBytes().str_repeat("\0", ($limitMb + 1) * 1024 * 1024);

        $this->upload($this->andi, 'besar.png', $big)
            ->assertStatus(413)
            ->assertJsonPath('error.code', 'PAYLOAD_TOO_LARGE');

        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_tanpa_file_atau_file_kosong_ditolak_422(): void
    {
        $this->actingAs($this->andi)->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/attachments', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->upload($this->andi, 'kosong.png', '')->assertStatus(422);
    }

    public function test_pdf_txt_dan_csv_diterima_sebagai_file(): void
    {
        $this->upload($this->andi, 'laporan.pdf', $this->pdfBytes())
            ->assertCreated()
            ->assertJsonPath('data.attachment.kind', 'file')
            ->assertJsonPath('data.attachment.mime_type', 'application/pdf')
            ->assertJsonPath('data.attachment.width', null);

        $this->upload($this->andi, 'catatan.txt', "halo dunia\n")
            ->assertCreated()->assertJsonPath('data.attachment.mime_type', 'text/plain');

        $this->upload($this->andi, 'data.csv', "a,b\n1,2\n")
            ->assertCreated()->assertJsonPath('data.attachment.mime_type', 'text/csv');
    }

    public function test_nama_file_disanitasi(): void
    {
        $response = $this->upload($this->andi, "../../etc/pa\x01ss.png", $this->pngBytes())->assertCreated();

        $name = $response->json('data.attachment.original_name');
        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString('..', $name);
        $this->assertStringNotContainsString("\x01", $name);
    }

    public function test_upload_butuh_login(): void
    {
        $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/attachments', ['file' => UploadedFile::fake()->createWithContent('a.png', $this->pngBytes())])
            ->assertStatus(401);
        $this->getJson('/api/v1/attachments/1/content')->assertStatus(401);
        $this->deleteJson('/api/v1/attachments/1')->assertStatus(401);
    }

    // ================= LAMPIRAN PADA PESAN =================

    public function test_kirim_pesan_dengan_lampiran_tanpa_teks(): void
    {
        $a = $this->uploadPng($this->andi, 'satu.png');
        $b = $this->uploadPng($this->andi, 'dua.png');

        $response = $this->sendWith($this->andi, ['attachment_ids' => [$a, $b]])
            ->assertCreated()
            ->assertJsonPath('data.message.body', null)
            ->assertJsonCount(2, 'data.message.attachments')
            ->assertJsonPath('data.message.attachments.0.id', $a)
            ->assertJsonPath('data.message.attachments.1.id', $b);

        $messageId = $response->json('data.message.id');
        $this->assertSame($messageId, $response->json('data.message.attachments.0.message_id'));
        $this->assertSame(2, Attachment::where('message_id', $messageId)->count());
    }

    public function test_kirim_pesan_dengan_teks_dan_lampiran_serta_notifikasi_lampiran(): void
    {
        $a = $this->uploadPng($this->andi);

        $this->sendWith($this->andi, ['body' => 'Lihat ini', 'attachment_ids' => [$a]])
            ->assertCreated()
            ->assertJsonPath('data.message.body', 'Lihat ini')
            ->assertJsonCount(1, 'data.message.attachments');

        $b = $this->uploadPng($this->andi);
        $this->sendWith($this->andi, ['attachment_ids' => [$b]])->assertCreated();

        // Pesan tanpa teks -> isi notifikasi "Mengirim lampiran"
        $this->assertDatabaseHas('notifications', ['user_id' => $this->budi->id, 'body' => 'Mengirim lampiran']);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->budi->id, 'body' => 'Lihat ini']);
    }

    public function test_pesan_tanpa_teks_dan_tanpa_lampiran_ditolak(): void
    {
        $this->sendWith($this->andi, [])->assertStatus(422);
        $this->sendWith($this->andi, ['attachment_ids' => []])->assertStatus(422);
        $this->sendWith($this->andi, ['body' => '   ', 'attachment_ids' => []])->assertStatus(422);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_lampiran_milik_orang_lain_sudah_dipakai_tidak_ada_duplikat_atau_lebih_dari_5_ditolak(): void
    {
        $mine = $this->uploadPng($this->andi);
        $others = $this->uploadPng($this->budi);

        $this->sendWith($this->andi, ['attachment_ids' => [$others]])->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertNull(Attachment::find($others)->message_id); // tidak terambil

        $this->sendWith($this->andi, ['attachment_ids' => [99999]])->assertStatus(422);
        $this->sendWith($this->andi, ['attachment_ids' => [$mine, $mine]])->assertStatus(422); // duplikat

        $ids = [];
        for ($i = 0; $i < 6; $i++) {
            $ids[] = $this->pendingAttachment($this->andi)->id;
        }
        $this->sendWith($this->andi, ['attachment_ids' => $ids])->assertStatus(422); // maks 5

        $this->sendWith($this->andi, ['attachment_ids' => [$mine]])->assertCreated();
        $this->sendWith($this->andi, ['attachment_ids' => [$mine]])->assertStatus(422); // sudah dipakai pesan lain

        $this->assertDatabaseCount('messages', 1);
    }

    public function test_kirim_ulang_dengan_client_uuid_sama_tetap_berhasil_walau_lampiran_sudah_terpakai(): void
    {
        $a = $this->uploadPng($this->andi);
        $uuid = (string) Str::uuid();

        $first = $this->sendWith($this->andi, ['client_uuid' => $uuid, 'attachment_ids' => [$a]])->assertCreated();
        $second = $this->sendWith($this->andi, ['client_uuid' => $uuid, 'attachment_ids' => [$a]])->assertOk();

        $this->assertSame($first->json('data.message.id'), $second->json('data.message.id'));
        $this->assertCount(1, $second->json('data.message.attachments'));
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_message_new_membawa_metadata_lampiran(): void
    {
        $a = $this->uploadPng($this->andi);

        config(['chat.realtime_enabled' => true]);
        $captured = [];
        Redis::shouldReceive('publish')->once()->with('chat.notifications', Mockery::any());
        Redis::shouldReceive('publish')
            ->twice()
            ->with('chat.messages', Mockery::on(function ($json) use (&$captured) {
                $captured[] = json_decode($json, true);

                return true;
            }));

        $this->sendWith($this->andi, ['attachment_ids' => [$a]])->assertCreated();

        $attachments = $captured[0]['data']['message']['attachments'];
        $this->assertCount(1, $attachments);
        $this->assertSame($a, $attachments[0]['id']);
        $this->assertSame('image', $attachments[0]['kind']);
        $this->assertSame("/api/v1/attachments/$a/content", $attachments[0]['url']);
        $this->assertArrayNotHasKey('path', $attachments[0]); // path internal tidak bocor
        $this->assertArrayNotHasKey('disk', $attachments[0]);
    }

    public function test_riwayat_daftar_percakapan_dan_balasan_memuat_lampiran(): void
    {
        $a = $this->uploadPng($this->andi);
        $messageId = $this->sendWith($this->andi, ['attachment_ids' => [$a]])->json('data.message.id');
        $this->sendWith($this->budi, ['body' => 'Mantap', 'reply_to_id' => $messageId])
            ->assertCreated()
            ->assertJsonPath('data.message.reply_to.has_attachments', true);

        $this->actingAs($this->budi)->getJson("/api/v1/conversations/{$this->conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.messages.0.attachments.0.id', $a);

        $this->actingAs($this->andi)->getJson('/api/v1/conversations')
            ->assertOk()
            ->assertJsonPath('data.conversations.0.last_message.body', 'Mantap');
    }

    // ================= AKSES / UNDUH =================

    public function test_akses_lampiran_pending_hanya_pengunggah(): void
    {
        $id = $this->uploadPng($this->andi);

        $this->actingAs($this->andi)->get("/api/v1/attachments/$id/content")->assertOk();
        $this->actingAs($this->budi)->get("/api/v1/attachments/$id/content")->assertStatus(404);
        $this->actingAs($this->citra)->get("/api/v1/attachments/$id/content")->assertStatus(404);
    }

    public function test_akses_lampiran_terlampir_hanya_peserta_percakapan(): void
    {
        $id = $this->uploadPng($this->andi);
        $this->sendWith($this->andi, ['attachment_ids' => [$id]])->assertCreated();

        $this->actingAs($this->andi)->get("/api/v1/attachments/$id/content")->assertOk();
        $this->actingAs($this->budi)->get("/api/v1/attachments/$id/content")->assertOk();
        $this->actingAs($this->citra)->get("/api/v1/attachments/$id/content")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->actingAs($this->budi)->get('/api/v1/attachments/99999/content')->assertStatus(404);
    }

    public function test_header_unduhan_inline_untuk_gambar_dan_paksa_unduh_untuk_dokumen(): void
    {
        $png = $this->uploadPng($this->andi, 'foto.png');
        $pdf = $this->upload($this->andi, 'laporan.pdf', $this->pdfBytes())->assertCreated()->json('data.attachment.id');
        $this->sendWith($this->andi, ['attachment_ids' => [$png, $pdf]])->assertCreated();

        $image = $this->actingAs($this->budi)->get("/api/v1/attachments/$png/content")->assertOk();
        $image->assertHeader('Content-Type', 'image/png');
        $image->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('inline', $image->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', $image->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', $image->headers->get('Cache-Control')); // jangan boleh di-cache proxy bersama

        $forced = $this->actingAs($this->budi)->get("/api/v1/attachments/$png/content?download=1")->assertOk();
        $this->assertStringContainsString('attachment', $forced->headers->get('Content-Disposition'));

        // Dokumen SELALU diunduh, tidak pernah dirender di browser
        $doc = $this->actingAs($this->budi)->get("/api/v1/attachments/$pdf/content")->assertOk();
        $doc->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment', $doc->headers->get('Content-Disposition'));
        $this->assertStringContainsString('laporan.pdf', $doc->headers->get('Content-Disposition'));
    }

    public function test_range_request_untuk_seeking_video_audio(): void
    {
        $id = $this->uploadPng($this->andi);
        $size = Attachment::find($id)->size_bytes;

        $response = $this->actingAs($this->andi)
            ->withHeaders(['Range' => 'bytes=0-9'])
            ->get("/api/v1/attachments/$id/content");

        $response->assertStatus(206);
        $this->assertSame("bytes 0-9/$size", $response->headers->get('Content-Range'));
    }

    public function test_file_tidak_bisa_diambil_setelah_pesan_dihapus(): void
    {
        $id = $this->uploadPng($this->andi);
        $messageId = $this->sendWith($this->andi, ['attachment_ids' => [$id]])->json('data.message.id');
        $path = Attachment::find($id)->path;

        $this->actingAs($this->andi)->deleteJson("/api/v1/messages/$messageId")
            ->assertOk()
            ->assertJsonPath('data.message.attachments', []);

        $this->assertDatabaseMissing('attachments', ['id' => $id]);
        Storage::disk(config('chat.media_disk'))->assertMissing($path);
        $this->actingAs($this->budi)->get("/api/v1/attachments/$id/content")->assertStatus(404);
    }

    // ================= HAPUS LAMPIRAN PENDING =================

    public function test_hapus_lampiran_pending_milik_sendiri(): void
    {
        $id = $this->uploadPng($this->andi);
        $path = Attachment::find($id)->path;

        $this->actingAs($this->budi)->deleteJson("/api/v1/attachments/$id")->assertStatus(404);
        Storage::disk(config('chat.media_disk'))->assertExists($path);

        $this->actingAs($this->andi)->deleteJson("/api/v1/attachments/$id")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('attachments', ['id' => $id]);
        Storage::disk(config('chat.media_disk'))->assertMissing($path);
    }

    public function test_lampiran_yang_sudah_terlampir_tidak_bisa_dihapus(): void
    {
        $id = $this->uploadPng($this->andi);
        $this->sendWith($this->andi, ['attachment_ids' => [$id]])->assertCreated();

        $this->actingAs($this->andi)->deleteJson("/api/v1/attachments/$id")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ATTACHMENT_ALREADY_ATTACHED');

        $this->assertDatabaseHas('attachments', ['id' => $id]);
    }

    // ================= PEMBERSIHAN FILE YATIM =================

    public function test_prune_menghapus_hanya_lampiran_pending_yang_kedaluwarsa(): void
    {
        $old = $this->pendingAttachment($this->andi);
        $recent = $this->pendingAttachment($this->andi);
        $attachedOld = $this->pendingAttachment($this->andi);

        $message = Message::create([
            'conversation_id' => $this->conversation->id, 'sender_id' => $this->andi->id,
            'recipient_id' => $this->budi->id, 'client_uuid' => (string) Str::uuid(), 'body' => 'x', 'revision' => 1,
        ]);
        $attachedOld->update(['message_id' => $message->id]);

        Attachment::whereIn('id', [$old->id, $attachedOld->id])->update(['created_at' => now()->subHours(30)]);
        Attachment::where('id', $recent->id)->update(['created_at' => now()->subHour()]);

        $this->artisan('attachments:prune')->assertSuccessful();

        $this->assertDatabaseMissing('attachments', ['id' => $old->id]);
        Storage::disk(config('chat.media_disk'))->assertMissing($old->path);

        $this->assertDatabaseHas('attachments', ['id' => $recent->id]);        // baru: tetap
        $this->assertDatabaseHas('attachments', ['id' => $attachedOld->id]);   // sudah terlampir: tetap
        Storage::disk(config('chat.media_disk'))->assertExists($attachedOld->path);
    }
}
