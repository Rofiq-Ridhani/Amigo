<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Pemeriksaan kesehatan setup Amigo: `php artisan amigo:doctor`.
 * Status: OK / PERINGATAN (sebaiknya diperbaiki) / GAGAL (harus diperbaiki, exit code 1).
 */
class AmigoDoctor extends Command
{
    protected $signature = 'amigo:doctor {--skip-network : Lewati pengecekan Redis dan server Socket.IO}';

    protected $description = 'Periksa konfigurasi, database, Redis, server Socket.IO, dan keamanan dasar Amigo';

    /** @var array<int,array{0:string,1:string,2:string}> */
    private array $rows = [];

    public function handle(): int
    {
        $this->checkSecrets();
        $this->checkApp();
        $this->checkStorage();
        $this->checkDatabase();

        if (! $this->option('skip-network')) {
            $this->checkRedis();
            $this->checkSocketServer();
        }

        $this->checkUploadLimits();

        $this->table(['Status', 'Pemeriksaan', 'Keterangan'], $this->rows);

        $failed = count(array_filter($this->rows, fn ($r) => $r[0] === 'GAGAL'));
        $warned = count(array_filter($this->rows, fn ($r) => $r[0] === 'PERINGATAN'));

        if ($failed > 0) {
            $this->error("{$failed} pemeriksaan GAGAL, {$warned} peringatan.");

            return self::FAILURE;
        }

        $this->info($warned > 0 ? "Tidak ada yang gagal, {$warned} peringatan." : 'Semua pemeriksaan OK.');

        return self::SUCCESS;
    }

    private function add(string $status, string $name, string $detail = ''): void
    {
        $this->rows[] = [$status, $name, $detail];
    }

    private function checkSecrets(): void
    {
        $socket = (string) config('chat.socket_secret');
        $internal = (string) config('chat.internal_secret');

        $this->add(strlen($socket) >= 32 ? 'OK' : 'GAGAL', 'SOCKET_SECRET', strlen($socket) >= 32 ? 'terisi' : 'kosong atau kurang dari 32 karakter');
        $this->add(strlen($internal) >= 32 ? 'OK' : 'GAGAL', 'INTERNAL_API_SECRET', strlen($internal) >= 32 ? 'terisi' : 'kosong atau kurang dari 32 karakter');

        if ($socket !== '' && $socket === $internal) {
            $this->add('GAGAL', 'Dua secret berbeda', 'SOCKET_SECRET dan INTERNAL_API_SECRET harus BERBEDA (kontrak §I no. 4)');
        } else {
            $this->add('OK', 'Dua secret berbeda');
        }
    }

    private function checkApp(): void
    {
        $this->add(config('app.key') ? 'OK' : 'GAGAL', 'APP_KEY', config('app.key') ? 'terisi' : 'kosong: jalankan php artisan key:generate');

        if (app()->isProduction()) {
            $this->add(config('app.debug') ? 'GAGAL' : 'OK', 'APP_DEBUG di produksi', config('app.debug') ? 'harus false' : 'false');
            $this->add(config('session.secure') ? 'OK' : 'PERINGATAN', 'Cookie sesi Secure', config('session.secure') ? 'aktif' : 'isi SESSION_SECURE_COOKIE=true (HTTPS)');
        }

        $this->add('OK', 'Mode CSP', (string) config('chat.csp.mode'));
    }

    private function checkStorage(): void
    {
        $disk = (string) config('chat.media_disk');
        $config = (array) config("filesystems.disks.$disk");
        $root = (string) ($config['root'] ?? '');

        $public = $disk === 'public' || ($config['visibility'] ?? null) === 'public';
        if (! $public && $root !== '' && is_dir($root) && is_dir(public_path())) {
            $public = str_starts_with((string) realpath($root), (string) realpath(public_path()));
        }

        $this->add($public ? 'GAGAL' : 'OK', 'Disk lampiran privat', $public ? "disk '$disk' bisa diakses publik" : "disk '$disk'");

        // Folder dibuat otomatis saat upload pertama; yang penting folder induk terdekat bisa ditulis
        $probe = $root;
        while ($probe !== '' && $probe !== dirname($probe) && ! is_dir($probe)) {
            $probe = dirname($probe);
        }
        $writable = $root !== '' && $probe !== '' && is_dir($probe) && is_writable($probe);
        $this->add($writable ? 'OK' : 'GAGAL', 'Folder lampiran bisa ditulis', $root !== '' ? $root : 'root tidak dikenali');

        $this->add(is_writable(storage_path('logs')) ? 'OK' : 'PERINGATAN', 'storage/logs bisa ditulis');
    }

    private function checkDatabase(): void
    {
        try {
            DB::select('select 1');
            $this->add('OK', 'Koneksi database', (string) config('database.default'));
        } catch (Throwable $e) {
            $this->add('GAGAL', 'Koneksi database', mb_substr($e->getMessage(), 0, 90));

            return;
        }

        $missing = array_filter(
            ['users', 'conversations', 'conversation_members', 'messages', 'message_mentions', 'message_reactions', 'attachments', 'notifications', 'outbox_events'],
            fn ($t) => ! Schema::hasTable($t),
        );

        $this->add($missing === [] ? 'OK' : 'GAGAL', 'Tabel lengkap', $missing === [] ? '9 tabel ada' : 'hilang: '.implode(', ', $missing).' (jalankan php artisan migrate)');
    }

    private function checkRedis(): void
    {
        try {
            Redis::ping();
            $this->add('OK', 'Koneksi Redis', (string) config('database.redis.default.host'));
        } catch (Throwable $e) {
            $this->add('GAGAL', 'Koneksi Redis', mb_substr($e->getMessage(), 0, 90));
        }
    }

    private function checkSocketServer(): void
    {
        $url = (string) (config('chat.socket_health_url') ?: rtrim((string) config('chat.socket_url'), '/').'/health');

        try {
            $response = Http::timeout(3)->get($url);
            $json = $response->json() ?? [];

            if (! $response->ok()) {
                $this->add('GAGAL', 'Server Socket.IO', "HTTP {$response->status()} dari $url");
            } elseif (($json['redis'] ?? false) !== true) {
                $this->add('GAGAL', 'Server Socket.IO', 'hidup, tetapi TIDAK tersambung ke Redis (cek REDIS_HOST/REDIS_PASSWORD di .env)');
            } else {
                $this->add('OK', 'Server Socket.IO', "hidup dan tersambung ke Redis ({$json['online_users']} user online)");
            }
        } catch (Throwable) {
            $this->add('GAGAL', 'Server Socket.IO', "tidak dapat dihubungi di $url (jalankan npm start di folder websocket)");
        }
    }

    private function checkUploadLimits(): void
    {
        $this->add(
            'OK',
            'Batas upload PHP (CLI)',
            'upload_max_filesize='.ini_get('upload_max_filesize').', post_max_size='.ini_get('post_max_size').' — nilai php-fpm/Nginx bisa berbeda, lihat docs/RELIABILITY_DRILLS.md',
        );
    }
}
