<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Bersihkan lampiran yatim tiap jam (jalankan `php artisan schedule:work` saat development)
Schedule::command('attachments:prune')->hourly();

// Kirim ulang event realtime yang gagal dipublish ke Redis (kontrak §J)
Schedule::command('outbox:flush')->everyMinute()->withoutOverlapping();
