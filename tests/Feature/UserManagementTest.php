<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('an admin lists only scanner accounts', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $scanner = User::factory()->scanner()->create(['email' => 'scanner@example.com']);

    $this->actingAs($admin)
        ->getJson('/api/users')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $scanner->id);
});

test('an admin creates a scanner and the server controls its role', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)->postJson('/api/users', [
        'name' => 'Gate Scanner',
        'email' => ' SCANNER@example.com ',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => User::ROLE_ADMIN,
    ])->assertCreated()
        ->assertJsonPath('data.role', User::ROLE_SCANNER)
        ->assertJsonPath('data.email', 'scanner@example.com')
        ->assertJsonMissingPath('data.password');

    $scanner = User::where('email', 'scanner@example.com')->firstOrFail();
    expect(Hash::check('password123', $scanner->password))->toBeTrue();
});

test('an admin updates scanner details without replacing a blank password', function () {
    $admin = User::factory()->create();
    $scanner = User::factory()->scanner()->create();
    $originalPassword = $scanner->getAuthPassword();

    $this->actingAs($admin)->putJson('/api/users/'.$scanner->id, [
        'name' => 'Updated Scanner',
        'email' => ' '.strtoupper($scanner->email).' ',
        'password' => '',
        'password_confirmation' => '',
        'role' => User::ROLE_ADMIN,
    ])->assertOk()
        ->assertJsonPath('data.name', 'Updated Scanner')
        ->assertJsonPath('data.email', $scanner->email)
        ->assertJsonPath('data.role', User::ROLE_SCANNER);

    expect($scanner->refresh()->getAuthPassword())->toBe($originalPassword);
});

test('an admin replaces a scanner password when one is provided', function () {
    $admin = User::factory()->create();
    $scanner = User::factory()->scanner()->create();
    $originalPassword = $scanner->getAuthPassword();

    $this->actingAs($admin)->putJson('/api/users/'.$scanner->id, [
        'name' => $scanner->name,
        'email' => $scanner->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ])->assertOk();

    expect($scanner->refresh()->getAuthPassword())->not->toBe($originalPassword)
        ->and(Hash::check('newpassword123', $scanner->getAuthPassword()))->toBeTrue();
});

test('an admin deletes a scanner account', function () {
    $admin = User::factory()->create();
    $scanner = User::factory()->scanner()->create();

    $this->actingAs($admin)->deleteJson('/api/users/'.$scanner->id)
        ->assertOk()
        ->assertJsonPath('message', 'Scanner deleted successfully.');

    $this->assertDatabaseMissing('users', ['id' => $scanner->id]);
});

test('a duplicate email cannot create a scanner', function () {
    $admin = User::factory()->create();
    User::factory()->scanner()->create(['email' => 'existing@example.com']);

    $this->actingAs($admin)->postJson('/api/users', [
        'name' => 'Another Scanner',
        'email' => ' EXISTING@example.com ',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

test('an unchanged email is valid when editing a scanner', function () {
    $admin = User::factory()->create();
    $scanner = User::factory()->scanner()->create(['email' => 'scanner@example.com']);

    $this->actingAs($admin)->putJson('/api/users/'.$scanner->id, [
        'name' => 'Same Email Scanner',
        'email' => ' SCANNER@example.com ',
    ])->assertOk()
        ->assertJsonPath('data.email', 'scanner@example.com');
});

test('a scanner cannot be created with missing required values', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)->postJson('/api/users', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email', 'password']);
});

test('a scanner cannot be updated with missing required values', function () {
    $admin = User::factory()->create();
    $scanner = User::factory()->scanner()->create();

    $this->actingAs($admin)->putJson('/api/users/'.$scanner->id, [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email']);
});

test('a scanner cannot access any user management endpoint', function () {
    $scanner = User::factory()->scanner()->create();
    $target = User::factory()->scanner()->create();

    $this->actingAs($scanner)->getJson('/api/users')->assertForbidden();
    $this->actingAs($scanner)->postJson('/api/users', [
        'name' => 'New Scanner',
        'email' => 'new@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ])->assertForbidden();
    $this->actingAs($scanner)->putJson('/api/users/'.$target->id, [
        'name' => 'Changed Scanner',
        'email' => $target->email,
    ])->assertForbidden();
    $this->actingAs($scanner)->deleteJson('/api/users/'.$target->id)->assertForbidden();

    expect($target->refresh()->name)->not->toBe('Changed Scanner');
    $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
});

test('an admin cannot update an admin account through user management', function () {
    $admin = User::factory()->create();
    $target = User::factory()->create(['email' => 'other-admin@example.com']);
    $originalPassword = $target->getAuthPassword();

    $this->actingAs($admin)->putJson('/api/users/'.$target->id, [
        'name' => 'Changed Admin',
        'email' => 'changed-admin@example.com',
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ])->assertForbidden();

    $target->refresh();
    expect($target->email)->toBe('other-admin@example.com')
        ->and($target->name)->not->toBe('Changed Admin')
        ->and($target->getAuthPassword())->toBe($originalPassword);
});

test('an invalid update targeting an admin still returns forbidden', function () {
    $admin = User::factory()->create();
    $target = User::factory()->create(['email' => 'protected-admin@example.com']);
    $originalName = $target->name;

    $this->actingAs($admin)->putJson('/api/users/'.$target->id, [])
        ->assertForbidden();

    $target->refresh();
    expect($target->name)->toBe($originalName)
        ->and($target->email)->toBe('protected-admin@example.com');
});

test('an admin cannot delete an admin account through user management', function () {
    $admin = User::factory()->create();
    $target = User::factory()->create();

    $this->actingAs($admin)->deleteJson('/api/users/'.$target->id)->assertForbidden();

    $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => User::ROLE_ADMIN]);
});
