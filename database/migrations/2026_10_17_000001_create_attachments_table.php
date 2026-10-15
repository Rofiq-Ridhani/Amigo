<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            // NULL = sudah diunggah tetapi belum dilampirkan ke pesan (pending)
            $table->foreignId('message_id')->nullable()->constrained('messages')->cascadeOnDelete();
            $table->foreignId('uploader_id')->constrained('users');
            $table->string('disk', 32);                 // disk PRIVAT (bukan public)
            $table->string('path');                     // nama acak: attachments/{Y}/{m}/{uuid}.{ext}
            $table->string('original_name');            // hanya untuk tampilan/unduhan, sudah disanitasi
            $table->string('mime_type', 100);           // hasil deteksi server
            $table->enum('kind', ['image', 'video', 'audio', 'file']);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();   // hanya gambar
            $table->unsignedInteger('height')->nullable();
            $table->timestamps();

            $table->index('message_id');
            $table->index(['uploader_id', 'message_id', 'created_at']); // pembersihan file yatim
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
