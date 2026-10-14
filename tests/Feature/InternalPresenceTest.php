<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Uji endpoint internal /internal/v1/presence (kontrak §D.9).
 */
class InternalPresenceTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'rahasia-internal-test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['chat.internal_secret' => self::SECRET]);
    }

    /** @param array<string,mixed> $payload */
    private function signedPost(array $payload, ?int $timestamp = null, ?string $secret = null)
    {
        $raw = json_encode($payload);
        $timestamp ??= time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$raw, $secret ?? self::SECRET);

        return $this->call('POST', '/internal/v1/presence', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_INTERNAL_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_INTERNAL_SIGNATURE' => $signature,
        ], $raw);
    }

    public function test_offline_menyimpan_last_seen_at(): void
    {
        $user = User::factory()->create();

        $this->signedPost(['user_id' => $user->id, 'status' => 'offline', 'at' => '2026-10-13T02:30:00Z'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('2026-10-13 02:30:00', $user->fresh()->last_seen_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_online_tidak_mengubah_last_seen_at(): void
    {
        $user = User::factory()->create();

        $this->signedPost(['user_id' => $user->id, 'status' => 'online', 'at' => '2026-10-13T02:30:00Z'])->assertOk();

        $this->assertNull($user->fresh()->last_seen_at);
    }

    public function test_tanda_tangan_salah_ditolak_401(): void
    {
        $user = User::factory()->create();

        $this->signedPost(['user_id' => $user->id, 'status' => 'offline', 'at' => '2026-10-13T02:30:00Z'], null, 'secret-lain')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->assertNull($user->fresh()->last_seen_at);
    }

    public function test_timestamp_basi_ditolak_401(): void
    {
        $user = User::factory()->create();

        $this->signedPost(['user_id' => $user->id, 'status' => 'offline', 'at' => '2026-10-13T02:30:00Z'], time() - 3600)
            ->assertStatus(401);
    }

    public function test_tanpa_header_ditolak_401(): void
    {
        $this->postJson('/internal/v1/presence', ['user_id' => 1, 'status' => 'offline', 'at' => '2026-10-13T02:30:00Z'])
            ->assertStatus(401);
    }

    public function test_user_tidak_ada_422(): void
    {
        $this->signedPost(['user_id' => 99999, 'status' => 'offline', 'at' => '2026-10-13T02:30:00Z'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_secret_belum_diisi_503(): void
    {
        config(['chat.internal_secret' => '']);

        $this->signedPost(['user_id' => 1, 'status' => 'offline', 'at' => '2026-10-13T02:30:00Z'])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
    }
}
