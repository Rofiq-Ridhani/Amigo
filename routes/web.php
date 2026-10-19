<?php

use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MessageActionController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ReadStateController;
use App\Http\Controllers\Api\SocketTokenController;
use App\Http\Controllers\Api\ThreadController;
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
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/chat', [ChatController::class, 'index'])->name('chat');

    // ---- API v1 (session + CSRF, kontrak §D.0) ----
    Route::prefix('api/v1')->group(function () {
        Route::get('/me', MeController::class);
        Route::get('/socket-token', SocketTokenController::class)->middleware('throttle:30,1');
        Route::get('/users', [UserController::class, 'index'])->middleware('throttle:30,1');
        Route::get('/users/{id}', [UserController::class, 'show'])->whereNumber('id');

        // Fase 2: percakapan & pesan
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::get('/conversations/{id}', [ConversationController::class, 'show'])->whereNumber('id');
        Route::post('/conversations', [ConversationController::class, 'store'])->middleware('throttle:30,1');
        Route::get('/conversations/{id}/messages', [MessageController::class, 'index'])->whereNumber('id');
        Route::post('/conversations/{id}/messages', [MessageController::class, 'store'])
            ->whereNumber('id')
            ->middleware('throttle:30,1');

        // Fase 8: grup
        Route::post('/groups', [GroupController::class, 'store'])->middleware('throttle:10,1');
        Route::patch('/groups/{id}', [GroupController::class, 'update'])->whereNumber('id')->middleware('throttle:30,1');
        Route::post('/groups/{id}/members', [GroupController::class, 'addMembers'])->whereNumber('id')->middleware('throttle:30,1');
        Route::delete('/groups/{id}/members/{userId}', [GroupController::class, 'removeMember'])
            ->whereNumber(['id', 'userId'])
            ->middleware('throttle:30,1');
        Route::post('/groups/{id}/leave', [GroupController::class, 'leave'])->whereNumber('id')->middleware('throttle:30,1');

        // Fase 9: thread
        Route::get('/conversations/{id}/messages/{msg_id}/thread', [ThreadController::class, 'show'])
            ->whereNumber(['id', 'msg_id'])
            ->middleware('throttle:30,1');

        // Fase 3: status baca & unread
        Route::post('/conversations/{id}/read', [ReadStateController::class, 'markRead'])
            ->whereNumber('id')
            ->middleware('throttle:60,1');
        Route::get('/unread-counts', [ReadStateController::class, 'counts']);

        // Fase 5: edit, hapus, reaksi
        Route::patch('/messages/{id}', [MessageActionController::class, 'update'])
            ->whereNumber('id')
            ->middleware('throttle:30,1');
        Route::delete('/messages/{id}', [MessageActionController::class, 'destroy'])
            ->whereNumber('id')
            ->middleware('throttle:30,1');
        Route::put('/messages/{id}/reaction', [MessageActionController::class, 'react'])
            ->whereNumber('id')
            ->middleware('throttle:60,1');
        Route::delete('/messages/{id}/reaction', [MessageActionController::class, 'unreact'])
            ->whereNumber('id')
            ->middleware('throttle:60,1');

        // Fase 6: lampiran
        Route::post('/attachments', [AttachmentController::class, 'store'])->middleware('throttle:10,1');
        Route::delete('/attachments/{id}', [AttachmentController::class, 'destroy'])
            ->whereNumber('id')
            ->middleware('throttle:30,1');
        Route::get('/attachments/{id}/content', [AttachmentController::class, 'content'])->whereNumber('id');

        // Fase 4: notifikasi
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->middleware('throttle:60,1');
        Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])
            ->whereUuid('id')
            ->middleware('throttle:60,1');
    });
});
