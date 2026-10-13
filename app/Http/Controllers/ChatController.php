<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Halaman chat. View `chat.index` DIBUAT & DIMILIKI Frontend (kontrak §B.2/§B.3).
 * Backend hanya menjamin route + user login.
 */
class ChatController extends Controller
{
    public function index(): View
    {
        return view('chat.index');
    }
}
