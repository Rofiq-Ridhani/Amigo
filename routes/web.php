<?php

use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\SocketTokenController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/chat');

// ---- Halaman (view dibuat oleh Frontend: resources/views/auth/login.blade.php, dst.) ----
Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::view('/register', 'auth.register')->name('register');
});

// ---- Aksi auth (kontrak §D.2) ----
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/register', [AuthController::class, 'register']);

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/chat', [ChatController::class, 'index'])->name('chat');

    // ---- API v1 (session + CSRF, kontrak §D.0) ----
    Route::prefix('api/v1')->group(function () {
        Route::get('/me', MeController::class);
        Route::get('/socket-token', SocketTokenController::class)->middleware('throttle:30,1');
        Route::get('/users', [UserController::class, 'index'])->middleware('throttle:30,1');
        Route::get('/users/{id}', [UserController::class, 'show'])->whereNumber('id');
    });
});
