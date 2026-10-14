<?php

use App\Http\Controllers\Internal\PresenceController;
use Illuminate\Support\Facades\Route;

/*
 * Route internal — hanya dipanggil server Node (kontrak §D.9).
 * Prefix /internal/v1 + middleware VerifyInternalSignature didaftarkan di bootstrap/app.php.
 * Frontend DILARANG memakai endpoint ini.
 */

Route::post('/presence', PresenceController::class);
