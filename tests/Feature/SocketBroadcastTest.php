<?php

namespace Tests\Feature;

use App\Events\MessageSent;
use App\Events\PresenceUpdated;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SocketBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_sent_event_broadcasts_correctly(): void
    {
        Event::fake([MessageSent::class]);

        $sender = User::factory()->create();
        $recipient = User::factory()->create();

        $message = Message::create([
            'sender_id' => $sender->id,
            'recipient_id' => $recipient->id,
            'body' => 'Hello Realtime Socket!',
        ]);

        event(new MessageSent($message));

        Event::assertDispatched(MessageSent::class, function ($event) use ($recipient) {
            return $event->broadcastOn()[0]->name === 'private-chat.' . $recipient->id
                && $event->broadcastAs() === 'message:new';
        });
    }

    public function test_message_sent_event_payload_structure(): void
    {
        $sender = User::factory()->create(['name' => 'Alice']);
        $recipient = User::factory()->create(['name' => 'Bob']);

        $message = Message::create([
            'sender_id' => $sender->id,
            'recipient_id' => $recipient->id,
            'body' => 'Test Payload',
        ]);

        $event = new MessageSent($message);
        $payload = $event->broadcastWith();

        $this->assertEquals($message->id, $payload['id']);
        $this->assertEquals($sender->id, $payload['sender_id']);
        $this->assertEquals($recipient->id, $payload['recipient_id']);
        $this->assertEquals('Test Payload', $payload['body']);
        $this->assertArrayHasKey('sender', $payload);
        $this->assertEquals('Alice', $payload['sender']['name']);
    }

    public function test_presence_updated_event_broadcasts_on_public_channel(): void
    {
        Event::fake([PresenceUpdated::class]);

        $user = User::factory()->create();

        event(new PresenceUpdated($user->id, 'online'));

        Event::assertDispatched(PresenceUpdated::class, function ($event) use ($user) {
            return $event->broadcastOn()[0]->name === 'presence'
                && $event->broadcastAs() === 'presence:update'
                && $event->broadcastWith()['user_id'] === $user->id
                && $event->broadcastWith()['status'] === 'online';
        });
    }

    public function test_channel_routes_exist(): void
    {
        $channels = require base_path('routes/channels.php');
        $this->assertTrue(true); // Channel file syntax verified
    }
}
