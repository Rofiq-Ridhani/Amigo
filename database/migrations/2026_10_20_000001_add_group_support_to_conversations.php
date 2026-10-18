<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // 'direct' = 1-lawan-1 (perilaku lama, tidak berubah), 'group' = grup (kontrak §17)
            $table->string('type', 10)->default('direct')->after('id');
            $table->string('name', 50)->nullable()->after('type');                 // hanya grup
            $table->foreignId('created_by')->nullable()->after('name')->constrained('users')->nullOnDelete();
        });

        // Grup tidak punya pasangan user_one/user_two, dan pesan grup tidak punya satu penerima.
        $this->makeNullable('conversations', ['user_one_id', 'user_two_id']);
        $this->makeNullable('messages', ['recipient_id']);
    }

    public function down(): void
    {
        // Hapus data grup dulu agar kolom bisa dikembalikan menjadi NOT NULL
        DB::table('messages')->whereNull('recipient_id')->delete();
        DB::table('conversations')->where('type', 'group')->delete();

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['type', 'name']);
        });
    }

    /** @param  array<int,string>  $columns */
    private function makeNullable(string $table, array $columns): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // ALTER langsung: perilakunya paling dapat diprediksi pada kolom yang memiliki foreign key
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE `$table` MODIFY `$column` BIGINT UNSIGNED NULL");
            }

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns) {
            foreach ($columns as $column) {
                $blueprint->unsignedBigInteger($column)->nullable()->change();
            }
        });
    }
};
