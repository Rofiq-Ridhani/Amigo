<?php

namespace Database\Factories;

use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'sender_id' => 1,
            'recipient_id' => 2,
            'body' => $this->faker->sentence(),
            'is_edited' => false,
            'is_deleted' => false,
        ];
    }
}
