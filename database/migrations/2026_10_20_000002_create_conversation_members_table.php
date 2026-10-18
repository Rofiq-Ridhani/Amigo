<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Anggota GRUP saja. Percakapan direct tetap memakai user_one_id/user_two_id.
        Schema::create('conversation_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 10)->default('member');            // owner | member
            // Kursor baca: pesan dengan id <= ini sudah dibaca anggota (unread & "dibaca oleh")
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('left_at')->nullable();                 // diisi saat keluar/dikeluarkan
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'left_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_members');
    }
};
