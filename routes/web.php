<?php

use App\Http\Controllers\ApiController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Chat routes
    Route::get('/users', [ChatController::class, 'index'])->name('users.index');
    Route::get('/chat/{user}', [ChatController::class, 'show'])->name('chat.show');
    Route::post('/chat/{user}', [ChatController::class, 'store'])->name('chat.store');

    // Contract §4.4–4.5 — auth JSON for SPA/frontend
    Route::get('/api/me', [ApiController::class, 'me'])->name('api.me');
    Route::get('/socket-token', [ApiController::class, 'socketToken'])->name('socket.token');
});

require __DIR__.'/auth.php';
