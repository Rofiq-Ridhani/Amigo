<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 3 self-check — messages basic (contract §5.2/5.3).
 * Verifikasi: kirim, riwayat, reply, validasi 422, auth 401/302.
 */
class MessagesBasicTest extends TestCase
{
    use RefreshDatabase;

    private function users(): array
    {
        $a = User::factory()->create(['name' => 'Andi']);
        $b = User::factory()->create(['name' => 'Budi']);
        return [$a, $b];
    }

    public function test_send_message_returns_201_with_contract_shape(): void
    {
        [$a, $b] = $this->users();

        $res = $this->actingAs($a)->postJson('/messages', [
            'recipient_id' => $b->id,
            'body' => 'Halo Budi',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.body', 'Halo Budi')
            ->assertJsonPath('data.sender_id', $a->id)
            ->assertJsonPath('data.recipient_id', $b->id)
            ->assertJsonStructure(['data' => ['id', 'sender_id', 'sender_name', 'recipient_id', 'body', 'reply_to_id', 'created_at']]);

        $this->assertDatabaseHas('messages', [
            'sender_id' => $a->id,
            'recipient_id' => $b->id,
            'body' => 'Halo Budi',
        ]);
    }

    public function test_send_to_self_is_422(): void
    {
        [$a] = $this->users();
        $this->actingAs($a)->postJson('/messages', [
            'recipient_id' => $a->id,
            'body' => 'hi',
        ])->assertStatus(422);
    }

    public function test_send_without_body_and_attachment_is_422(): void
    {
        [$a, $b] = $this->users();
        $this->actingAs($a)->postJson('/messages', [
            'recipient_id' => $b->id,
        ])->assertStatus(422);
    }

    public function test_reply_to_valid_message(): void
    {
        [$a, $b] = $this->users();
        $parent = Message::create(['sender_id' => $a->id, 'recipient_id' => $b->id, 'body' => 'Pesan 1']);

        $res = $this->actingAs($b)->postJson('/messages', [
            'recipient_id' => $a->id,
            'body' => 'Balasan',
            'reply_to_id' => $parent->id,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.reply_to_id', $parent->id)
            ->assertJsonPath('data.reply_to_preview.body', 'Pesan 1');
    }

    public function test_reply_to_foreign_message_is_422(): void
    {
        [$a, $b] = $this->users();
        $c = User::factory()->create();
        $foreign = Message::create(['sender_id' => $a->id, 'recipient_id' => $c->id, 'body' => 'bukan punya B']);

        $this->actingAs($b)->postJson('/messages', [
            'recipient_id' => $a->id,
            'body' => 'nge-reply',
            'reply_to_id' => $foreign->id,
        ])->assertStatus(422);
    }

    public function test_conversation_history_between_two_users_only(): void
    {
        [$a, $b] = $this->users();
        $c = User::factory()->create();

        Message::create(['sender_id' => $a->id, 'recipient_id' => $b->id, 'body' => 'A->B']);
        Message::create(['sender_id' => $b->id, 'recipient_id' => $a->id, 'body' => 'B->A']);
        Message::create(['sender_id' => $a->id, 'recipient_id' => $c->id, 'body' => 'A->C rahasia']);

        $res = $this->actingAs($a)->getJson("/api/conversations/{$b->id}/messages");

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('pagination.total', 2);

        $bodies = collect($res->json('data'))->pluck('body')->all();
        $this->assertNotContains('A->C rahasia', $bodies);
    }

    public function test_history_requires_auth(): void
    {
        [$a, $b] = $this->users();
        $this->getJson("/api/conversations/{$b->id}/messages")->assertStatus(401);
    }

    public function test_unread_count_groups_by_sender(): void
    {
        [$a, $b] = $this->users();
        Message::create(['sender_id' => $a->id, 'recipient_id' => $b->id, 'body' => '1']);
        Message::create(['sender_id' => $a->id, 'recipient_id' => $b->id, 'body' => '2']);

        $this->actingAs($b)->getJson('/api/unread-count')
            ->assertStatus(200)
            ->assertJsonPath('data.total_unread', 2)
            ->assertJsonPath("data.conversations.{$a->id}", 2);
    }

    public function test_mark_read_clears_unread(): void
    {
        [$a, $b] = $this->users();
        Message::create(['sender_id' => $a->id, 'recipient_id' => $b->id, 'body' => '1']);

        $this->actingAs($b)->postJson("/api/conversations/{$a->id}/mark-read")
            ->assertStatus(200)
            ->assertJsonPath('data.unread_count', 0);

        $this->assertDatabaseMissing('messages', ['read_at' => null, 'sender_id' => $a->id]);
    }

    public function test_users_list_excludes_self(): void
    {
        [$a, $b] = $this->users();
        $res = $this->actingAs($a)->getJson('/api/users');
        $res->assertStatus(200);
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertNotContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
    }
}
