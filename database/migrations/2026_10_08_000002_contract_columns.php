<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('messages', 'reply_to_id')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->foreignId('reply_to_id')->nullable()->after('message')
                    ->constrained('messages')->nullOnDelete();
                $table->boolean('is_edited')->default(false)->after('read_at');
                $table->boolean('is_deleted')->default(false)->after('is_edited');
                $table->timestamp('deleted_at')->nullable()->after('is_deleted');
                $table->timestamp('delivered_at')->nullable()->after('deleted_at');
                $table->timestamp('edited_at')->nullable()->after('delivered_at');
            });
        }
        if (! Schema::hasColumn('users', 'last_seen_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('last_seen_at')->nullable()->after('email');
                $table->string('avatar')->nullable()->after('last_seen_at');
            });
        }
        if (! Schema::hasTable('media_attachments')) {
            Schema::create('media_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
                $table->enum('type', ['image', 'video', 'audio', 'file']);
                $table->string('file_path', 500);
                $table->string('thumbnail_path', 500)->nullable();
                $table->string('original_name');
                $table->string('mime_type', 100)->nullable();
                $table->unsignedBigInteger('file_size')->default(0);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('message_reactions')) {
            Schema::create('message_reactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('emoji', 50);
                $table->timestamps();
                $table->unique(['message_id', 'user_id']);
            });
        }
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('type', 50)->default('chat.message');
                $table->string('title');
                $table->text('body')->nullable();
                $table->json('data')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'read_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reactions');
        Schema::dropIfExists('media_attachments');
        Schema::dropIfExists('notifications');
        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasColumn('messages', 'reply_to_id')) {
                $table->dropForeign(['reply_to_id']);
            }
            foreach (['edited_at', 'delivered_at', 'deleted_at', 'is_deleted', 'is_edited', 'reply_to_id'] as $c) {
                if (Schema::hasColumn('messages', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        Schema::table('users', function (Blueprint $table) {
            foreach (['avatar', 'last_seen_at'] as $c) {
                if (Schema::hasColumn('users', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
