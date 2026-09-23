<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $admin = config('app.seed_admin');

        User::updateOrCreate(
            ['email' => $admin['email']],
            [
                'name' => $admin['name'],
                'role' => 'Admin',
                'email_verified_at' => now(),
                'password' => $admin['password'],
            ],
        );
    }
}
