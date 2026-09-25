<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('a supported user can replace their own password', function (string $role) {
    $user = User::factory()->create(['role' => $role]);

    $response = $this->actingAs($user)->putJson('/api/password', [
        'current_password' => 'password',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('message', 'Password updated successfully.');

    expect(Hash::check('new-password-123', $user->refresh()->password))->toBeTrue();
    $this->assertAuthenticatedAs($user);
})->with([
    'admin' => [User::ROLE_ADMIN],
    'scanner' => [User::ROLE_SCANNER],
]);

test('the current password must be correct', function () {
    $user = User::factory()->create();
    $originalPassword = $user->password;

    $this->actingAs($user)->putJson('/api/password', [
        'current_password' => 'incorrect-password',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

    expect($user->refresh()->password)->toBe($originalPassword);
});

test('the new password must be confirmed', function () {
    $user = User::factory()->create();
    $originalPassword = $user->password;

    $this->actingAs($user)->putJson('/api/password', [
        'current_password' => 'password',
        'password' => 'new-password-123',
        'password_confirmation' => 'different-password',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');

    expect($user->refresh()->password)->toBe($originalPassword);
});

test('a guest cannot update a password', function () {
    $this->putJson('/api/password', [
        'current_password' => 'password',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertUnauthorized();
});
