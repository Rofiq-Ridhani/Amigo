<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users');
            $table->foreignId('recipient_id')->constrained('users');
            // Kunci idempotensi dari client (kontrak §D.5)
            $table->char('client_uuid', 36);
            $table->foreignId('reply_to_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->text('body')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            // Naik 1 setiap perubahan; dipakai client untuk membuang data usang (kontrak §H.7)
            $table->unsignedInteger('revision')->default(1);
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['sender_id', 'client_uuid']);
            $table->index(['conversation_id', 'id']);
            $table->index(['recipient_id', 'read_at', 'conversation_id']);
            $table->index(['conversation_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
