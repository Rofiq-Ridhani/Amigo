<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Uji Fase 1 sesuai kontrak §D.2, §D.3, §F.
 */
class Phase1ApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['chat.socket_secret' => 'rahasia-untuk-test']);
    }

    public function test_login_berhasil_json(): void
    {
        $user = User::factory()->create(['email' => 'andi@example.com']);

        $this->postJson('/login', ['email' => 'andi@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id);

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_gagal_422_format_kontrak(): void
    {
        User::factory()->create(['email' => 'andi@example.com']);

        $this->postJson('/login', ['email' => 'andi@example.com', 'password' => 'salah'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.details.email.0', 'Email atau password salah.');

        $this->assertGuest();
    }

    public function test_register_membuat_user_dan_login(): void
    {
        $this->postJson('/register', [
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated()->assertJsonPath('data.user.name', 'Budi Santoso');

        $this->assertDatabaseHas('users', ['email' => 'budi@example.com']);
        $this->assertAuthenticated();
    }

    public function test_logout(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertGuest();
    }

    public function test_api_tanpa_login_401_format_kontrak(): void
    {
        $this->getJson('/api/v1/users')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_me_mengembalikan_email_sendiri(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', $user->email);
    }

    public function test_daftar_user_tanpa_diri_sendiri_dan_tanpa_email(): void
    {
        $me = User::factory()->create(['name' => 'Saya']);
        User::factory()->create(['name' => 'Andi Pratama']);
        User::factory()->create(['name' => 'Budi Santoso']);

        $response = $this->actingAs($me)->getJson('/api/v1/users')->assertOk();

        $response->assertJsonCount(2, 'data.users')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonMissingPath('data.users.0.email');

        $this->assertStringNotContainsString('email', $response->getContent());
    }

    public function test_pencarian_user_berdasarkan_nama(): void
    {
        $me = User::factory()->create();
        User::factory()->create(['name' => 'Andi Pratama']);
        User::factory()->create(['name' => 'Budi Santoso']);

        $this->actingAs($me)->getJson('/api/v1/users?q=andi')
            ->assertOk()
            ->assertJsonCount(1, 'data.users')
            ->assertJsonPath('data.users.0.name', 'Andi Pratama');
    }

    public function test_pencarian_kurang_dari_2_karakter_ditolak(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/users?q=a')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_user_tidak_ditemukan_404(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/users/99999')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_socket_token_valid(): void
    {
        $user = User::factory()->create();

        $data = $this->actingAs($user)->getJson('/api/v1/socket-token')
            ->assertOk()
            ->json('data');

        [$h, $p, $s] = explode('.', $data['token']);

        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$p", 'rahasia-untuk-test', true)), '+/', '-_'), '=');
        $this->assertSame($expected, $s);

        $claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
        $this->assertSame($user->id, $claims['sub']);
        $this->assertSame('amigo-socket', $claims['aud']);
        $this->assertGreaterThan(time(), $claims['exp']);
        $this->assertSame(config('chat.socket_url'), $data['socket_url']);
    }

    public function test_socket_token_butuh_login(): void
    {
        $this->getJson('/api/v1/socket-token')->assertStatus(401);
    }
}
