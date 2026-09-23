<?php

use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Support\Facades\Hash;

test('the admin seeder creates an idempotent administrator account', function () {
    config()->set('app.seed_admin', [
        'name' => 'Test Administrator',
        'email' => 'admin@example.com',
        'password' => 'SecurePassword123!',
    ]);

    $this->seed(AdminSeeder::class);
    $this->seed(AdminSeeder::class);

    $admin = User::where('email', 'admin@example.com')->firstOrFail();

    expect(User::where('email', 'admin@example.com')->count())->toBe(1)
        ->and($admin->name)->toBe('Test Administrator')
        ->and($admin->role)->toBe('Admin')
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and(Hash::check('SecurePassword123!', $admin->password))->toBeTrue();
});
