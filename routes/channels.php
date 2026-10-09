<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Contract §6.4 — Private chat room per user (recipient).
 */
Broadcast::channel('chat.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

/**
 * Contract §6.6 — Private notification room per user.
 */
Broadcast::channel('notif.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

/**
 * Contract §6.2 — Public presence channel (all users).
 */
Broadcast::channel('presence', function ($user) {
    return true;
});
