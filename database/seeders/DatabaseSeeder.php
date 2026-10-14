<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Akun demo untuk development (password: "password"). Jangan dipakai di production.
     * Aman dijalankan berulang: akun yang sudah ada tidak diduplikasi.
     */
    public function run(): void
    {
        $demo = [
            ['name' => 'Test User', 'email' => 'test@example.com'],
            ['name' => 'Andi Pratama', 'email' => 'andi@example.com'],
            ['name' => 'Budi Santoso', 'email' => 'budi@example.com'],
        ];

        foreach ($demo as $account) {
            User::firstOrCreate(
                ['email' => $account['email']],
                ['name' => $account['name'], 'password' => 'password'],
            );
        }
    }
}
