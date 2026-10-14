<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            // UUID: dipakai client untuk mencegah toast ganda (kontrak §C.6)
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // penerima
            $table->string('type', 50);          // v1: chat.message
            $table->string('title');
            $table->text('body')->nullable();    // teks polos
            $table->json('data');                // {conversation_id, message_id, sender_id, url}
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
