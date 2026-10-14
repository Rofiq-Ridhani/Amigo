<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\User;
use App\Services\MessageService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Alat bantu development: kirim pesan tanpa frontend untuk menguji jalur realtime.
 * Contoh: php artisan amigo:send 1 2 "Halo Budi"
 */
class SendTestMessage extends Command
{
    protected $signature = 'amigo:send {from : ID user pengirim} {to : ID user penerima} {body : Isi pesan}';

    protected $description = 'Kirim pesan uji antar dua user (untuk tes realtime tanpa frontend)';

    public function handle(MessageService $service): int
    {
        $from = User::find($this->argument('from'));
        $to = User::find($this->argument('to'));

        if ($from === null || $to === null || $from->is($to)) {
            $this->error('User pengirim/penerima tidak valid (dan tidak boleh sama).');

            return self::FAILURE;
        }

        $conversation = Conversation::between($from->id, $to->id);

        [$message] = $service->send($conversation, $from, (string) Str::uuid(), (string) $this->argument('body'));

        $this->info("Pesan #{$message->id} dari {$from->name} ke {$to->name} tersimpan & dipublish.");

        return self::SUCCESS;
    }
}
