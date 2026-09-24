<?php

use App\Models\Attendee;
use App\Models\EmailInvitation;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

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
                'key' => 'last-name',
                'system_key' => 'last_name',
                'type' => 'short',
                'label' => 'Last name',
                'description' => null,
                'required' => true,
                'options' => [],
            ],
            [
                'key' => 'first-name',
                'system_key' => 'first_name',
                'type' => 'short',
                'label' => 'First name',
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
        ->assertJsonPath('data.active_registration_form.fields.0.system_key', 'last_name')
        ->assertJsonPath('data.active_registration_form.fields.1.system_key', 'first_name')
        ->assertJsonCount(4, 'data.active_registration_form.fields');

    $this->assertDatabaseHas('events', ['created_by' => $user->id, 'status' => 'published']);
    $this->assertDatabaseCount('registration_forms', 1);
    $this->assertDatabaseCount('form_fields', 4);
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
            'first-name' => 'Jamie',
            'last-name' => 'Rivera',
            'work-email' => 'JAMIE@example.com',
            'meal' => $mealValue,
        ],
    ]);

    $registrationResponse
        ->assertCreated()
        ->assertJsonPath('data.attendee.email', 'jamie@example.com');

    expect(Attendee::count())->toBe(1)
        ->and(Registration::count())->toBe(1);
    $this->assertDatabaseCount('registration_answers', 4);

    $this->actingAs($user)
        ->getJson('/api/events/'.$event->slug.'/registrations')
        ->assertOk()
        ->assertJsonPath('data.0.attendee.first_name', 'Jamie')
        ->assertJsonPath('data.0.attendee.last_name', 'Rivera');
});

test('an attendee cannot register for the same event twice', function () {
    $user = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($user)->postJson('/api/events', eventPayload())->json('data.id'),
    );
    $payload = ['answers' => ['first-name' => 'Jamie', 'last-name' => 'Rivera', 'work-email' => 'jamie@example.com', 'meal' => 'regular-1']];

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
            'first_name' => 'Jamie',
            'last_name' => 'Rivera',
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
        ->assertJsonCount(5, 'data.active_registration_form.fields');

    expect($event->registrationForms()->count())->toBe(2)
        ->and($event->registrationForms()->where('is_active', true)->count())->toBe(1);
});

test('an event owner can send a normalized batch of registration invitations', function () {
    $user = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($user)->postJson('/api/events', eventPayload())->json('data.id'),
    );
    Mail::fake();

    $this->actingAs($user)
        ->postJson('/api/events/'.$event->slug.'/invitations', [
            'emails' => [' Guest@example.com ', 'guest@example.com', 'SECOND@example.com'],
        ])
        ->assertOk()
        ->assertJsonPath('sent_count', 2)
        ->assertJsonPath('failed_count', 0)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('registration_url', 'http://localhost:3000/register/'.$event->slug);

    $this->assertDatabaseCount('email_invitations', 2);
    $this->assertDatabaseHas('email_invitations', [
        'event_id' => $event->id,
        'email' => 'guest@example.com',
        'status' => 'sent',
    ]);
    $this->assertDatabaseHas('email_invitations', [
        'event_id' => $event->id,
        'email' => 'second@example.com',
        'status' => 'sent',
    ]);
});

test('an event owner can retrieve only the twenty most recent invitation logs', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($owner)->postJson('/api/events', eventPayload())->json('data.id'),
    );

    foreach (range(1, 21) as $number) {
        EmailInvitation::create([
            'event_id' => $event->id,
            'email' => "guest{$number}@example.com",
            'status' => 'sent',
            'created_at' => now()->addSeconds($number),
            'updated_at' => now()->addSeconds($number),
        ]);
    }

    $this->actingAs($owner)
        ->getJson('/api/events/'.$event->slug.'/invitations')
        ->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('data.0.email', 'guest21@example.com')
        ->assertJsonPath('data.0.event.title', $event->title)
        ->assertJsonMissingPath('data.0.failure_reason')
        ->assertJsonMissingPath('data.0.invited_by');

    $this->actingAs($otherUser)
        ->getJson('/api/events/'.$event->slug.'/invitations')
        ->assertNotFound();
});

test('an event owner can retrieve every registration for an excel export', function () {
    $owner = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($owner)->postJson('/api/events', eventPayload())->json('data.id'),
    );
    $form = $event->activeRegistrationForm()->firstOrFail();

    foreach (range(1, 51) as $number) {
        $attendee = Attendee::create([
            'first_name' => 'Guest',
            'last_name' => (string) $number,
            'email' => "guest{$number}@example.com",
            'email_normalized' => "guest{$number}@example.com",
        ]);

        $event->registrations()->create([
            'registration_form_id' => $form->id,
            'attendee_id' => $attendee->id,
            'registration_code' => 'REG-'.str_pad((string) $number, 8, '0', STR_PAD_LEFT),
            'status' => 'confirmed',
            'source' => 'public_form',
            'registered_at' => now()->addSeconds($number),
            'confirmed_at' => now()->addSeconds($number),
        ]);
    }

    $this->actingAs($owner)
        ->getJson('/api/events/'.$event->slug.'/registrations/export')
        ->assertOk()
        ->assertJsonCount(51, 'data')
        ->assertJsonPath('data.0.attendee.email', 'guest51@example.com');
});

test('a user cannot export another owners event registrations', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($owner)->postJson('/api/events', eventPayload())->json('data.id'),
    );

    $this->actingAs($otherUser)
        ->getJson('/api/events/'.$event->slug.'/registrations/export')
        ->assertNotFound();
});

test('an invitation batch rejects invalid addresses and more than one hundred recipients', function () {
    $user = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($user)->postJson('/api/events', eventPayload())->json('data.id'),
    );

    $this->actingAs($user)
        ->postJson('/api/events/'.$event->slug.'/invitations', [
            'emails' => ['not-an-email'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('emails.0');

    $this->actingAs($user)
        ->postJson('/api/events/'.$event->slug.'/invitations', [
            'emails' => 'guest@example.com',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('emails');

    $this->actingAs($user)
        ->postJson('/api/events/'.$event->slug.'/invitations', [
            'emails' => collect(range(1, 101))->map(fn ($number) => "guest{$number}@example.com")->all(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('emails');
});

test('a failed invitation delivery is recorded without its failure reason', function () {
    $user = User::factory()->create();
    $event = Event::findOrFail(
        $this->actingAs($user)->postJson('/api/events', eventPayload())->json('data.id'),
    );
    Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('SMTP credentials exposed here'));

    $response = $this->actingAs($user)
        ->postJson('/api/events/'.$event->slug.'/invitations', [
            'emails' => ['guest@example.com'],
        ])
        ->assertOk()
        ->assertJsonPath('sent_count', 0)
        ->assertJsonPath('failed_count', 1);

    expect($response->getContent())->not->toContain('SMTP credentials exposed here');

    $this->assertDatabaseHas('email_invitations', [
        'event_id' => $event->id,
        'email' => 'guest@example.com',
        'status' => 'failed',
    ]);
});
