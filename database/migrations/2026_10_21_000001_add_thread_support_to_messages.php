<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 9: Thread (balasan bergaya Slack di grup).
 * thread_id menunjuk ke pesan asal thread; balasan flat (satu tingkat).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('thread_id')->nullable()->after('reply_to_id')
                ->constrained('messages')->cascadeOnDelete();
            $table->index(['thread_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['thread_id']);
            $table->dropColumn('thread_id');
        });
    }
};
