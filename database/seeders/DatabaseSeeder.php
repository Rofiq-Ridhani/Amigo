<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Existing test user
        User::updateOrCreate(
            ['email' => 'user@example.com'],
            ['name' => 'Test User', 'password' => Hash::make('password')]
        );

        // Dummy accounts for chat testing
        User::updateOrCreate(
            ['email' => 'dhani@example.com'],
            ['name' => 'Dhani', 'password' => Hash::make('password')]
        );

        User::updateOrCreate(
            ['email' => 'andi@example.com'],
            ['name' => 'Andi', 'password' => Hash::make('password')]
        );

        User::updateOrCreate(
            ['email' => 'budi@example.com'],
            ['name' => 'Budi', 'password' => Hash::make('password')]
        );
    }
}
