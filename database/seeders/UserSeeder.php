<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'password',
                'role' => 'trader',
                'email_verified_at' => '2025-11-23T22:06:41.000000Z',
            ],
            [
                'name' => 'Guest User',
                'email' => 'guest@tikrtracker.com',
                'password' => 'password',
                'role' => 'guest',
                'email_verified_at' => '2025-11-23T22:06:41.000000Z',
            ],
            [
                'name' => 'Admin User',
                'email' => 'admin@admin.com',
                'password' => 'password',
                'role' => 'admin',
                'email_verified_at' => '2025-11-23T22:06:41.000000Z',
            ],
        ];

        foreach ($users as $user) {
            User::firstOrCreate(
                ['email' => $user['email']],
                $user
            );
        }
    }
}
