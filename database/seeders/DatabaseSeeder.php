<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'author@example.com'],
            [
                'name' => 'Avery Author',
                'password' => Hash::make('password'),
                'role' => UserRole::Author,
            ],
        );

        User::updateOrCreate(
            ['email' => 'editor@example.com'],
            [
                'name' => 'Elliot Editor',
                'password' => Hash::make('password'),
                'role' => UserRole::Editor,
            ],
        );

        $this->command?->info('Seeded author@example.com / password and editor@example.com / password.');
    }
}
