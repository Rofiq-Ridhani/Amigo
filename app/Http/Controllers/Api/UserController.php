<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Daftar & pencarian pengguna (kontrak §D.3). Email TIDAK PERNAH dikembalikan. */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'min:2', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $q = $validated['q'] ?? null;

        $paginator = User::query()
            ->where('id', '!=', $request->user()->id)
            ->when($q !== null && $q !== '', function ($query) use ($q) {
                // escape karakter wildcard LIKE agar "%" / "_" dianggap teks biasa
                $escaped = addcslashes($q, '\\%_');
                $query->where('name', 'like', '%'.$escaped.'%');
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage);

        return ApiResponse::success(
            ['users' => $paginator->getCollection()->map(fn (User $u) => $u->toPublicArray())->values()->all()],
            'OK',
            200,
            [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }

    public function show(int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        return ApiResponse::success(['user' => $user->toPublicArray()]);
    }
}
