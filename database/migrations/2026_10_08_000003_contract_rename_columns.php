<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rename boiler columns to CONTRACT naming:
 *   messages.receiver_id → messages.recipient_id
 *   messages.message    → messages.body
 *
 * Frontend note: boiler uses POST /chat/{user} (receiver_id+message).
 * Contract §5.2 uses POST /messages {recipient_id, body}.
 * Both routes will work after this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasColumn('messages', 'receiver_id') && ! Schema::hasColumn('messages', 'recipient_id')) {
                $table->renameColumn('receiver_id', 'recipient_id');
            }
            if (Schema::hasColumn('messages', 'message') && ! Schema::hasColumn('messages', 'body')) {
                $table->renameColumn('message', 'body');
            }
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasColumn('messages', 'recipient_id') && ! Schema::hasColumn('messages', 'receiver_id')) {
                $table->renameColumn('recipient_id', 'receiver_id');
            }
            if (Schema::hasColumn('messages', 'body') && ! Schema::hasColumn('messages', 'message')) {
                $table->renameColumn('body', 'message');
            }
        });
    }
};
