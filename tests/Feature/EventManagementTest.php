<?php

use App\Models\Attendee;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;

function eventPayload(): array
{
    return [
        'title' => 'Annual Corporate Gala 2026',
        'description' => 'Company celebration.',
        'category' => 'Corporate Gala',
        'capacity' => 180,
        'venue' => 'Skyline Lounge',
        'starts_at' => now()->addWeek()->toIso8601String(),
        'ends_at' => now()->addWeek()->addHours(4)->toIso8601String(),
        'timezone' => 'Asia/Manila',
        'status' => 'published',
        'registration_form' => [
            [
                'key' => 'full-name',
                'system_key' => 'full_name',
                'type' => 'short',
                'label' => 'Full name',
                'description' => null,
                'required' => true,
                'options' => [],
            ],
            [
                'key' => 'work-email',
                'system_key' => 'email',
                'type' => 'short',
                'label' => 'Work email',
                'description' => null,
                'required' => true,
                'options' => [],
            ],
            [
                'key' => 'meal',
                'system_key' => null,
                'type' => 'dropdown',
                'label' => 'Meal preference',
                'description' => null,
                'required' => true,
                'options' => ['Regular', 'Vegetarian'],
            ],
        ],
    ];
}

test('an authenticated user can publish an event with a registration form', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/events', eventPayload());

    $response
        ->assertCreated()
        ->assertJsonPath('data.title', 'Annual Corporate Gala 2026')
        ->assertJsonCount(3, 'data.active_registration_form.fields');

    $this->assertDatabaseHas('events', ['created_by' => $user->id, 'status' => 'published']);
    $this->assertDatabaseCount('registration_forms', 1);
    $this->assertDatabaseCount('form_fields', 3);
    $this->assertDatabaseCount('field_options', 2);
});

test('a guest can register and the owner can retrieve the attendee', function () {
    $user = User::factory()->create();
    $eventResponse = $this->actingAs($user)->postJson('/api/events', eventPayload())->assertCreated();
    $event = Event::findOrFail($eventResponse->json('data.id'));
    $mealValue = $event->activeRegistrationForm()->firstOrFail()
        ->fields()->where('key', 'meal')->firstOrFail()
        ->options()->firstOrFail()->value;

    $registrationResponse = $this->postJson('/api/registration/events/'.$event->slug, [
        'answers' => [
            'full-name' => 'Jamie Rivera',
            'work-email' => 'JAMIE@example.com',
            'meal' => $mealValue,
        ],
    ]);

    $registrationResponse
        ->assertCreated()
        ->assertJsonPath('data.attendee.email', 'jamie@example.com');

    expect(Attendee::count())->toBe(1)
        ->and(Registration::count())->toBe(1);
    $this->assertDatabaseCount('registration_answers', 3);

    $this->actingAs($user)
        ->getJson('/api/events/'.$event->slug.'/registrations')
        ->assertOk()
        ->assertJsonPath('data.0.attendee.full_name', 'Jamie Rivera');
});

test('an attendee cannot register for the same event twice', function () {
    $user = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($user)->postJson('/api/events', eventPayload())->json('data.id'),
    );
    $payload = ['answers' => ['full-name' => 'Jamie Rivera', 'work-email' => 'jamie@example.com', 'meal' => 'regular-1']];

    $this->postJson('/api/registration/events/'.$event->slug, $payload)->assertCreated();
    $this->postJson('/api/registration/events/'.$event->slug, $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('an event owner can check in a registered attendee', function () {
    $user = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($user)->postJson('/api/events', eventPayload())->json('data.id'),
    );
    $registration = $event->registrations()->create([
        'registration_form_id' => $event->activeRegistrationForm()->firstOrFail()->id,
        'attendee_id' => Attendee::create([
            'full_name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'email_normalized' => 'jamie@example.com',
        ])->id,
        'registration_code' => 'REG-CHECKIN01',
        'status' => 'confirmed',
        'source' => 'public_form',
        'registered_at' => now(),
        'confirmed_at' => now(),
    ]);

    $this->actingAs($user)->postJson('/api/events/'.$event->slug.'/check-ins', [
        'registration_code' => $registration->registration_code,
        'gate' => 'Dashboard',
    ])->assertCreated()->assertJsonPath('data.result', 'accepted');

    $this->actingAs($user)->postJson('/api/events/'.$event->slug.'/check-ins', [
        'registration_code' => $registration->registration_code,
    ])->assertConflict()->assertJsonPath('data.result', 'duplicate');
});

test('an event owner can update an event and publish a new form version', function () {
    $user = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($user)->postJson('/api/events', eventPayload())->json('data.id'),
    );
    $updatedPayload = eventPayload();
    $updatedPayload['title'] = 'Updated Corporate Gala';
    $updatedPayload['registration_form'][] = [
        'key' => 'department',
        'system_key' => null,
        'type' => 'short',
        'label' => 'Department',
        'description' => null,
        'required' => false,
        'options' => [],
    ];

    $this->actingAs($user)
        ->putJson('/api/events/'.$event->slug, $updatedPayload)
        ->assertOk()
        ->assertJsonPath('data.title', 'Updated Corporate Gala')
        ->assertJsonPath('data.active_registration_form.version', 2)
        ->assertJsonCount(4, 'data.active_registration_form.fields');

    expect($event->registrationForms()->count())->toBe(2)
        ->and($event->registrationForms()->where('is_active', true)->count())->toBe(1);
});

test('an event owner can send a registration invitation', function () {
    $user = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($user)->postJson('/api/events', eventPayload())->json('data.id'),
    );

    $this->actingAs($user)
        ->postJson('/api/events/'.$event->slug.'/invitations', ['email' => 'guest@example.com'])
        ->assertOk()
        ->assertJsonPath('registration_url', 'http://localhost:3000/register/'.$event->slug);
});
