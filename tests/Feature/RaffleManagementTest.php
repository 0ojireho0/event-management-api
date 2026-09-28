<?php

use App\Models\Attendee;
use App\Models\Event;
use App\Models\Registration;
use App\Models\RegistrationForm;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

function raffleRegistration(User $owner, Attendee $attendee, string $slug): array
{
    $event = Event::create([
        'created_by' => $owner->id,
        'title' => $slug,
        'slug' => $slug,
        'starts_at' => '2026-10-01 09:00:00',
        'ends_at' => '2026-10-01 17:00:00',
    ]);
    $form = RegistrationForm::create([
        'event_id' => $event->id,
        'title' => 'Registration',
    ]);
    $registration = Registration::create([
        'event_id' => $event->id,
        'registration_form_id' => $form->id,
        'attendee_id' => $attendee->id,
        'registration_code' => 'REG-'.$slug,
        'registered_at' => '2026-09-28 09:00:00',
    ]);

    return [$event, $registration];
}

test('an event stores raffle draw lifecycle records', function () {
    $owner = User::factory()->create();
    $attendee = Attendee::create([
        'first_name' => 'Jamie',
        'last_name' => 'Rivera',
        'email' => 'jamie@example.com',
        'email_normalized' => 'jamie@example.com',
    ]);
    [$event, $registration] = raffleRegistration($owner, $attendee, 'raffle-lifecycle');

    $draw = $event->raffleDraws()->create([
        'registration_id' => $registration->id,
        'selected_by' => $owner->id,
        'status' => 'pending',
        'selected_at' => '2026-09-28 10:00:00',
        'expires_at' => '2026-09-28 10:10:00',
    ]);

    expect($event->raffleDraws()->firstOrFail()->is($draw))->toBeTrue()
        ->and($registration->raffleDraws()->firstOrFail()->is($draw))->toBeTrue()
        ->and($draw->event->is($event))->toBeTrue()
        ->and($draw->registration->is($registration))->toBeTrue()
        ->and($draw->selectedBy->is($owner))->toBeTrue()
        ->and($draw->status)->toBe('pending')
        ->and($draw->selected_at)->toBeInstanceOf(Carbon::class)
        ->and($draw->expires_at)->toBeInstanceOf(Carbon::class)
        ->and($draw->confirmed_at)->toBeNull()
        ->and($draw->cancelled_at)->toBeNull();
});

test('an event registration can only be a raffle winner once', function () {
    $owner = User::factory()->create();
    $attendee = Attendee::create([
        'first_name' => 'Jamie',
        'last_name' => 'Rivera',
        'email' => 'jamie@example.com',
        'email_normalized' => 'jamie@example.com',
    ]);
    [$event, $registration] = raffleRegistration($owner, $attendee, 'raffle-unique');

    $firstDraw = $event->raffleDraws()->create([
        'registration_id' => $registration->id,
        'status' => 'confirmed',
        'selected_at' => '2026-09-28 10:00:00',
        'confirmed_at' => '2026-09-28 10:01:00',
        'expires_at' => '2026-09-28 10:10:00',
    ]);
    $secondDraw = $event->raffleDraws()->create([
        'registration_id' => $registration->id,
        'status' => 'confirmed',
        'selected_at' => '2026-09-28 11:00:00',
        'confirmed_at' => '2026-09-28 11:01:00',
        'expires_at' => '2026-09-28 11:10:00',
    ]);

    $winner = $event->raffleWinners()->create([
        'registration_id' => $registration->id,
        'raffle_draw_id' => $firstDraw->id,
        'confirmed_by' => $owner->id,
        'won_at' => '2026-09-28 10:01:00',
    ]);

    expect($registration->raffleWinner->is($winner))->toBeTrue()
        ->and($winner->raffleDraw->is($firstDraw))->toBeTrue()
        ->and($winner->confirmedBy->is($owner))->toBeTrue()
        ->and($winner->won_at)->toBeInstanceOf(Carbon::class);

    expect(fn () => $event->raffleWinners()->create([
        'registration_id' => $registration->id,
        'raffle_draw_id' => $secondDraw->id,
        'won_at' => '2026-09-28 11:01:00',
    ]))->toThrow(QueryException::class);

    $this->assertDatabaseCount('raffle_winners', 1);
});

test('the same attendee can win raffles in different events', function () {
    $owner = User::factory()->create();
    $attendee = Attendee::create([
        'first_name' => 'Jamie',
        'last_name' => 'Rivera',
        'email' => 'jamie@example.com',
        'email_normalized' => 'jamie@example.com',
    ]);
    [$firstEvent, $firstRegistration] = raffleRegistration($owner, $attendee, 'raffle-first');
    [$secondEvent, $secondRegistration] = raffleRegistration($owner, $attendee, 'raffle-second');

    foreach ([[$firstEvent, $firstRegistration], [$secondEvent, $secondRegistration]] as [$event, $registration]) {
        $draw = $event->raffleDraws()->create([
            'registration_id' => $registration->id,
            'status' => 'confirmed',
            'selected_at' => '2026-09-28 10:00:00',
            'confirmed_at' => '2026-09-28 10:01:00',
            'expires_at' => '2026-09-28 10:10:00',
        ]);
        $event->raffleWinners()->create([
            'registration_id' => $registration->id,
            'raffle_draw_id' => $draw->id,
            'won_at' => '2026-09-28 10:01:00',
        ]);
    }

    $this->assertDatabaseCount('raffle_winners', 2);
    expect($firstEvent->raffleWinners()->count())->toBe(1)
        ->and($secondEvent->raffleWinners()->count())->toBe(1)
        ->and($firstRegistration->raffleWinner->registration_id)->toBe($firstRegistration->id)
        ->and($secondRegistration->raffleWinner->registration_id)->toBe($secondRegistration->id);
});
