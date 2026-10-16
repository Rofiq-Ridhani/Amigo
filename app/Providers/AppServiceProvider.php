<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Login: 5 percobaan / menit per email + IP (kontrak §I no. 9)
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip());
        });

        // Registrasi: 10 / menit per IP (mencegah pembuatan akun massal)
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(10)->by((string) $request->ip());
        });

        // Redis tidak boleh membuat request menggantung bila server Redis tidak merespons (kontrak §J)
        $timeout = (float) config('chat.redis_timeout');
        config([
            'database.redis.default.timeout' => $timeout,
            'database.redis.default.read_timeout' => $timeout,
            'database.redis.default.read_write_timeout' => $timeout, // nama opsi untuk klien predis
        ]);
    }
}
