<?php

use App\Models\User;

test('an admin can access event management routes', function () {
    $admin = User::factory()->create(['role' => 'Admin']);

    $this->actingAs($admin)->getJson('/api/events')->assertOk();
});

test('a scanner cannot access event management routes', function () {
    $scanner = User::factory()->create(['role' => 'Scanner']);

    $this->actingAs($scanner)->getJson('/api/events')->assertForbidden();
});

test('unknown and null roles cannot access admin routes', function (string|null $role) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)->getJson('/api/events')->assertForbidden();
})->with([null, 'admin', 'Operator']);

test('both supported roles can read their profile and log out', function (string $role) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('role', $role);

    $this->withHeaders([
        'Origin' => 'http://localhost:3000',
        'Referer' => 'http://localhost:3000/',
    ])->postJson('/api/logout')->assertNoContent();
})->with(['Admin', 'Scanner']);

test('role helpers and scanner factory state use exact role values', function () {
    $admin = User::factory()->make();
    $scanner = User::factory()->scanner()->make();

    expect($admin->role)->toBe('Admin')
        ->and($admin->isAdmin())->toBeTrue()
        ->and($admin->isScanner())->toBeFalse()
        ->and($scanner->role)->toBe('Scanner')
        ->and($scanner->isAdmin())->toBeFalse()
        ->and($scanner->isScanner())->toBeTrue();
});
