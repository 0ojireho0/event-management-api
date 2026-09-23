<?php

use App\Models\User;

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertNoContent();
});

test('users can authenticate using the api login endpoint', function () {
    $user = User::factory()->create();

    $response = $this
        ->withHeaders([
            'Origin' => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000/',
        ])
        ->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ]);

    $this->assertAuthenticatedAs($user);
    $response->assertNoContent();
});

test('authenticated users can retrieve themselves from the api', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('email', $user->email);
});

test('users can logout using the api endpoint', function () {
    $user = User::factory()->create();

    $headers = [
        'Origin' => 'http://localhost:3000',
        'Referer' => 'http://localhost:3000/',
    ];

    $this->withHeaders($headers)->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertNoContent();

    $this->assertAuthenticatedAs($user);

    $response = $this->withHeaders($headers)->postJson('/api/logout');

    $response->assertNoContent();
    $this->withHeaders($headers)->getJson('/api/user')->assertUnauthorized();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertNoContent();
});
