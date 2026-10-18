<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\User;
use App\Services\MessageService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Alat bantu development: kirim pesan ke grup. Contoh: php artisan amigo:send-group 5 1 "Halo @Budi" --mention=2 */
class SendGroupMessage extends Command
{
    protected $signature = 'amigo:send-group {group : ID grup} {from : ID pengirim} {body : Isi pesan} {--mention=* : ID user yang di-mention}';

    protected $description = 'Kirim pesan uji ke grup (untuk tes realtime tanpa frontend)';

    public function handle(MessageService $service): int
    {
        $group = Conversation::where('type', Conversation::TYPE_GROUP)->find($this->argument('group'));
        $from = User::find($this->argument('from'));

        if ($group === null || $from === null || ! $group->hasParticipant($from->id)) {
            $this->error('Grup tidak ada, atau pengirim bukan anggota aktif grup.');

            return self::FAILURE;
        }

        [$message] = $service->send(
            $group,
            $from,
            (string) Str::uuid(),
            (string) $this->argument('body'),
            null,
            [],
            array_map('intval', (array) $this->option('mention')),
        );

        $this->info("Pesan #{$message->id} dari {$from->name} ke grup \"{$group->name}\" tersimpan & dipublish.");

        return self::SUCCESS;
    }
}
