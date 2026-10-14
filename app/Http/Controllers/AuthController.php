<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Login, register, logout (kontrak §D.2).
 * Form Blade biasa -> redirect. Jika Accept: application/json -> JSON format §F.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse|RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $remember = (bool) ($credentials['remember'] ?? false);

        if (! Auth::attempt(['email' => $credentials['email'], 'password' => $credentials['password']], $remember)) {
            // Pesan sama untuk email tidak terdaftar & password salah
            throw ValidationException::withMessages(['email' => 'Email atau password salah.']);
        }

        $request->session()->regenerate(); // cegah session fixation

        if ($request->expectsJson()) {
            return ApiResponse::success(['user' => $this->sessionUser($request->user())], 'Login berhasil');
        }

        return redirect()->intended('/chat');
    }

    public function register(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create($data); // password di-hash otomatis oleh cast "hashed"

        Auth::login($user);
        $request->session()->regenerate();

        if ($request->expectsJson()) {
            return ApiResponse::success(['user' => $this->sessionUser($user)], 'Registrasi berhasil', 201);
        }

        return redirect('/chat');
    }

    public function logout(Request $request): JsonResponse|RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return ApiResponse::success(null, 'Logout berhasil');
        }

        return redirect('/login');
    }

    /**
     * @return array{id:int,name:string}
     */
    private function sessionUser(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name];
    }
}
