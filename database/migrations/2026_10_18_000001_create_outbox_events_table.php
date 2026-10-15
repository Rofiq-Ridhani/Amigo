<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Outbox (kontrak §J, D-06): event yang gagal dipublish ke Redis dikirim ulang oleh `outbox:flush`.
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->char('event_id', 36)->unique();   // dipakai ulang saat kirim ulang -> client membuang duplikat
            $table->string('channel');                // channel Redis tujuan
            $table->longText('payload');              // envelope JSON utuh
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['published_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
