<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\GroupService;
use Illuminate\Console\Command;

/** Alat bantu development: buat grup tanpa frontend. Contoh: php artisan amigo:group 1 "Tim Magang" 2 3 */
class MakeTestGroup extends Command
{
    protected $signature = 'amigo:group {owner : ID pembuat (pemilik)} {name : Nama grup} {members* : ID anggota lain}';

    protected $description = 'Buat grup uji (untuk tes realtime tanpa frontend)';

    public function handle(GroupService $groups): int
    {
        $owner = User::find($this->argument('owner'));

        if ($owner === null) {
            $this->error('User pembuat tidak ditemukan.');

            return self::FAILURE;
        }

        $ids = User::whereIn('id', $this->argument('members'))->pluck('id')->all();
        $group = $groups->create($owner, (string) $this->argument('name'), $ids);

        $this->info("Grup #{$group->id} \"{$group->name}\" dibuat oleh {$owner->name} dengan ".count($group->participantIds()).' anggota.');

        return self::SUCCESS;
    }
}
