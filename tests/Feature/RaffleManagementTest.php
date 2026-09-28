<?php

use App\Models\Attendee;
use App\Models\Event;
use App\Models\FormField;
use App\Models\Registration;
use App\Models\RegistrationForm;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

function raffleAddRegistration(Event $event, string $email, string $status = 'confirmed'): Registration
{
    $attendee = Attendee::create([
        'first_name' => ucfirst(explode('@', $email)[0]),
        'last_name' => 'Guest',
        'email' => $email,
        'email_normalized' => $email,
    ]);

    return $event->registrations()->create([
        'registration_form_id' => $event->registrationForms()->firstOrFail()->id,
        'attendee_id' => $attendee->id,
        'registration_code' => 'RAFFLE-'.$event->id.'-'.$attendee->id,
        'status' => $status,
        'registered_at' => now(),
    ]);
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

test('raffle state includes confirmed registrations with or without check in and excludes winners and active reservations', function () {
    $this->travelTo(Carbon::parse('2026-09-28 10:00:00'));
    $owner = User::factory()->create();
    $attendee = Attendee::create(['first_name' => 'Jamie', 'last_name' => 'Rivera', 'email' => 'jamie@example.com', 'email_normalized' => 'jamie@example.com']);
    [$event, $checkedIn] = raffleRegistration($owner, $attendee, 'raffle-state');
    $checkedIn->checkIns()->create(['result' => 'accepted', 'checked_in_at' => now()]);
    $notCheckedIn = raffleAddRegistration($event, 'no-check-in@example.com');
    raffleAddRegistration($event, 'rejected@example.com', 'rejected');
    raffleAddRegistration($event, 'cancelled@example.com', 'cancelled');
    $won = raffleAddRegistration($event, 'winner@example.com');
    $wonDraw = $event->raffleDraws()->create(['registration_id' => $won->id, 'status' => 'confirmed', 'selected_at' => now()->subMinutes(5), 'expires_at' => now()->addMinutes(5)]);
    $event->raffleWinners()->create(['registration_id' => $won->id, 'raffle_draw_id' => $wonDraw->id, 'won_at' => now()->subMinute()]);
    $reserved = raffleAddRegistration($event, 'reserved@example.com');
    $pending = $event->raffleDraws()->create(['registration_id' => $reserved->id, 'status' => 'pending', 'selected_at' => now(), 'expires_at' => now()->addMinutes(10)]);
    $expired = raffleAddRegistration($event, 'expired@example.com');
    $event->raffleDraws()->create(['registration_id' => $expired->id, 'status' => 'pending', 'selected_at' => now()->subMinutes(11), 'expires_at' => now()->subMinute()]);

    $response = $this->actingAs($owner)->getJson('/api/events/'.$event->slug.'/raffle')->assertOk();

    expect(collect($response->json('data.eligible_attendees'))->pluck('registration_id')->sort()->values()->all())
        ->toBe(collect([$checkedIn->id, $notCheckedIn->id, $expired->id])->sort()->values()->all());
    $response->assertJsonPath('data.eligible_count', 3)
        ->assertJsonPath('data.pending_draw.id', $pending->id)
        ->assertJsonPath('data.pending_draw.registration_id', $reserved->id)
        ->assertJsonPath('data.event.id', $event->id)
        ->assertJsonCount(1, 'data.winners');
});

test('raffle state masks email and omits raw identity and answers', function () {
    $owner = User::factory()->create();
    $attendee = Attendee::create(['first_name' => 'Jamie', 'last_name' => 'Rivera', 'email' => 'jamie@example.com', 'email_normalized' => 'jamie@example.com']);
    [$event, $registration] = raffleRegistration($owner, $attendee, 'raffle-privacy');
    $field = FormField::create([
        'registration_form_id' => $event->registrationForms()->firstOrFail()->id,
        'key' => 'secret-answer',
        'type' => 'short',
        'label' => 'Private answer',
    ]);
    $registration->answers()->create(['form_field_id' => $field->id, 'answer' => ['value' => 'private-answer-marker']]);

    $response = $this->actingAs($owner)->getJson('/api/events/'.$event->slug.'/raffle')->assertOk();

    $response->assertJsonPath('data.eligible_attendees.0', [
        'registration_id' => $registration->id,
        'first_name' => 'Jamie',
        'last_name' => 'Rivera',
        'masked_email' => 'j***@example.com',
    ]);
    expect($response->getContent())->not->toContain('jamie@example.com', 'private-answer-marker', 'answers', 'email_normalized');
});

test('only the event owner admin can read raffle state', function () {
    $owner = User::factory()->create();
    $otherAdmin = User::factory()->create();
    $scanner = User::factory()->scanner()->create();
    $attendee = Attendee::create(['first_name' => 'Jamie', 'last_name' => 'Rivera', 'email' => 'jamie@example.com', 'email_normalized' => 'jamie@example.com']);
    [$event] = raffleRegistration($owner, $attendee, 'raffle-access');

    $this->actingAs($otherAdmin)->getJson('/api/events/'.$event->slug.'/raffle')->assertNotFound();
    $this->actingAs($scanner)->getJson('/api/events/'.$event->slug.'/raffle')->assertForbidden();
});

test('raffle state returns all 1005 eligible registrations with bounded queries', function () {
    $owner = User::factory()->create();
    $attendee = Attendee::create(['first_name' => 'First', 'last_name' => 'Guest', 'email' => 'first@example.com', 'email_normalized' => 'first@example.com']);
    [$event] = raffleRegistration($owner, $attendee, 'raffle-large');
    $formId = $event->registrationForms()->firstOrFail()->id;
    $stamp = now()->toDateTimeString();
    foreach (array_chunk(range(2, 1005), 200) as $numbers) {
        DB::table('attendees')->insert(array_map(fn (int $n) => [
            'first_name' => 'Guest', 'last_name' => (string) $n,
            'email' => "guest{$n}@example.com", 'email_normalized' => "guest{$n}@example.com",
            'created_at' => $stamp, 'updated_at' => $stamp,
        ], $numbers));
    }
    $attendeeIds = DB::table('attendees')->where('email', 'like', 'guest%@example.com')->pluck('id');
    foreach ($attendeeIds->chunk(200) as $chunk) {
        DB::table('registrations')->insert($chunk->map(fn (int $id) => [
            'event_id' => $event->id, 'registration_form_id' => $formId, 'attendee_id' => $id,
            'registration_code' => 'RAFFLE-BULK-'.$id, 'status' => 'confirmed',
            'registered_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp,
        ])->all());
    }

    DB::enableQueryLog();
    DB::flushQueryLog();
    $response = $this->actingAs($owner)->getJson('/api/events/'.$event->slug.'/raffle')->assertOk();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    $ids = collect($response->json('data.eligible_attendees'))->pluck('registration_id');
    expect($ids)->toHaveCount(1005)
        ->and($ids->unique())->toHaveCount(1005)
        ->and($queryCount)->toBeLessThanOrEqual(10);
    $response->assertJsonPath('data.eligible_count', 1005);
});

test('raffle state returns newest confirmed winners first', function () {
    $owner = User::factory()->create();
    $attendee = Attendee::create(['first_name' => 'Jamie', 'last_name' => 'Rivera', 'email' => 'jamie@example.com', 'email_normalized' => 'jamie@example.com']);
    [$event, $first] = raffleRegistration($owner, $attendee, 'raffle-history');
    $second = raffleAddRegistration($event, 'second@example.com');
    foreach ([[$first, '2026-09-28 09:00:00'], [$second, '2026-09-28 10:00:00']] as [$registration, $wonAt]) {
        $draw = $event->raffleDraws()->create(['registration_id' => $registration->id, 'status' => 'confirmed', 'selected_at' => $wonAt, 'expires_at' => '2026-09-28 10:10:00']);
        $event->raffleWinners()->create(['registration_id' => $registration->id, 'raffle_draw_id' => $draw->id, 'won_at' => $wonAt]);
    }

    $response = $this->actingAs($owner)->getJson('/api/events/'.$event->slug.'/raffle')->assertOk();

    expect(collect($response->json('data.winners'))->pluck('registration_id')->all())->toBe([$second->id, $first->id]);
    $response->assertJsonPath('data.winners.0.first_name', 'Second')
        ->assertJsonPath('data.winners.0.masked_email', 's***@example.com')
        ->assertJsonPath('data.winners.0.won_at', '2026-09-28T10:00:00.000000Z')
        ->assertJsonPath('data.winners.1.registration_id', $first->id);
});

test('raffle state ignores draw and winner records whose registration belongs to another event', function () {
    $owner = User::factory()->create();
    $firstAttendee = Attendee::create(['first_name' => 'First', 'last_name' => 'Guest', 'email' => 'first@example.com', 'email_normalized' => 'first@example.com']);
    $secondAttendee = Attendee::create(['first_name' => 'Second', 'last_name' => 'Guest', 'email' => 'second@example.com', 'email_normalized' => 'second@example.com']);
    [$firstEvent, $firstRegistration] = raffleRegistration($owner, $firstAttendee, 'raffle-scoped-first');
    [$secondEvent, $secondRegistration] = raffleRegistration($owner, $secondAttendee, 'raffle-scoped-second');
    $foreignDraw = $firstEvent->raffleDraws()->create([
        'registration_id' => $secondRegistration->id,
        'status' => 'pending',
        'selected_at' => now(),
        'expires_at' => now()->addMinutes(10),
    ]);
    $firstEvent->raffleWinners()->create([
        'registration_id' => $secondRegistration->id,
        'raffle_draw_id' => $foreignDraw->id,
        'won_at' => now(),
    ]);

    $firstResponse = $this->actingAs($owner)->getJson('/api/events/'.$firstEvent->slug.'/raffle')->assertOk();
    $firstResponse->assertJsonPath('data.pending_draw', null)
        ->assertJsonCount(0, 'data.winners')
        ->assertJsonPath('data.eligible_attendees.0.registration_id', $firstRegistration->id);

    $secondResponse = $this->getJson('/api/events/'.$secondEvent->slug.'/raffle')->assertOk();
    $secondResponse->assertJsonPath('data.eligible_attendees.0.registration_id', $secondRegistration->id);
});

test('draw selects a confirmed registration server side and reserves it for exactly ten minutes', function () {
    $this->travelTo(Carbon::parse('2026-09-28 10:00:00'));
    $owner = User::factory()->create();
    $attendee = Attendee::create(['first_name' => 'Jamie', 'last_name' => 'Rivera', 'email' => 'jamie@example.com', 'email_normalized' => 'jamie@example.com']);
    [$event, $registration] = raffleRegistration($owner, $attendee, 'raffle-draw');
    raffleAddRegistration($event, 'rejected@example.com', 'rejected');

    $response = $this->actingAs($owner)->postJson("/api/events/{$event->slug}/raffle/draws", ['registration_id' => 999999])->assertCreated();

    $response->assertJsonPath('data.draw.status', 'pending')
        ->assertJsonPath('data.attendee.registration_id', $registration->id)
        ->assertJsonPath('data.attendee.masked_email', 'j***@example.com');
    expect($response->getContent())->not->toContain('jamie@example.com');
    $this->assertDatabaseHas('raffle_draws', [
        'event_id' => $event->id, 'registration_id' => $registration->id,
        'selected_by' => $owner->id, 'status' => 'pending',
        'selected_at' => '2026-09-28 10:00:00', 'expires_at' => '2026-09-28 10:10:00',
    ]);
});

test('repeated spins return the same active reservation and create one row', function () {
    $owner = User::factory()->create();
    $attendee = Attendee::create(['first_name' => 'Jamie', 'last_name' => 'Rivera', 'email' => 'jamie@example.com', 'email_normalized' => 'jamie@example.com']);
    [$event] = raffleRegistration($owner, $attendee, 'raffle-repeat');
    raffleAddRegistration($event, 'second@example.com');
    $url = "/api/events/{$event->slug}/raffle/draws";

    $first = $this->actingAs($owner)->postJson($url)->assertCreated();
    $second = $this->postJson($url)->assertOk();

    expect($second->json('data'))->toBe($first->json('data'));
    $this->assertDatabaseCount('raffle_draws', 1);
});

test('expiry at exactly ten minutes cancels the reservation and releases its registration', function () {
    $this->travelTo(Carbon::parse('2026-09-28 10:00:00'));
    $owner = User::factory()->create();
    $attendee = Attendee::create(['first_name' => 'Jamie', 'last_name' => 'Rivera', 'email' => 'jamie@example.com', 'email_normalized' => 'jamie@example.com']);
    [$event, $registration] = raffleRegistration($owner, $attendee, 'raffle-expiry');
    $url = "/api/events/{$event->slug}/raffle/draws";
    $first = $this->actingAs($owner)->postJson($url)->assertCreated();

    $this->travelTo(Carbon::parse('2026-09-28 10:10:00'));
    $second = $this->postJson($url)->assertCreated();

    expect($second->json('data.draw.id'))->not->toBe($first->json('data.draw.id'));
    $second->assertJsonPath('data.attendee.registration_id', $registration->id);
    $this->assertDatabaseHas('raffle_draws', ['id' => $first->json('data.draw.id'), 'status' => 'cancelled', 'cancelled_at' => '2026-09-28 10:10:00']);
    $this->assertDatabaseCount('raffle_draws', 2);
});

test('confirm records one winner and removes that registration from eligibility', function () {
    $owner = User::factory()->create();
    $attendee = Attendee::create(['first_name' => 'Jamie', 'last_name' => 'Rivera', 'email' => 'jamie@example.com', 'email_normalized' => 'jamie@example.com']);
    [$event, $registration] = raffleRegistration($owner, $attendee, 'raffle-confirm');
    $draw = $this->actingAs($owner)->postJson("/api/events/{$event->slug}/raffle/draws")->assertCreated()->json('data.draw.id');

    $this->postJson("/api/events/{$event->slug}/raffle/draws/{$draw}/confirm")->assertOk()
        ->assertJsonPath('data.draw.status', 'confirmed')
        ->assertJsonPath('data.winner.registration_id', $registration->id);

    $this->assertDatabaseHas('raffle_winners', ['event_id' => $event->id, 'registration_id' => $registration->id, 'raffle_draw_id' => $draw, 'confirmed_by' => $owner->id]);
    $this->assertDatabaseCount('raffle_winners', 1);
    $this->getJson("/api/events/{$event->slug}/raffle")->assertOk()->assertJsonPath('data.eligible_count', 0);
    $this->postJson("/api/events/{$event->slug}/raffle/draws/{$draw}/confirm")->assertStatus(409);
    $this->assertDatabaseCount('raffle_winners', 1);
});

test('cancel releases a registration and cannot cancel the same draw twice', function () {
    $owner = User::factory()->create();
    $attendee = Attendee::create(['first_name' => 'Jamie', 'last_name' => 'Rivera', 'email' => 'jamie@example.com', 'email_normalized' => 'jamie@example.com']);
    [$event, $registration] = raffleRegistration($owner, $attendee, 'raffle-cancel');
    $url = "/api/events/{$event->slug}/raffle/draws";
    $draw = $this->actingAs($owner)->postJson($url)->assertCreated()->json('data.draw.id');

    $this->deleteJson("{$url}/{$draw}")->assertOk()->assertJsonPath('data.draw.status', 'cancelled');
    $this->getJson("/api/events/{$event->slug}/raffle")->assertJsonPath('data.eligible_attendees.0.registration_id', $registration->id);
    $this->deleteJson("{$url}/{$draw}")->assertStatus(409);
});

test('confirmed draws select distinct registrations in one event', function () {
    $owner = User::factory()->create();
    $attendee = Attendee::create(['first_name' => 'Jamie', 'last_name' => 'Rivera', 'email' => 'jamie@example.com', 'email_normalized' => 'jamie@example.com']);
    [$event] = raffleRegistration($owner, $attendee, 'raffle-distinct');
    raffleAddRegistration($event, 'second@example.com');
    $url = "/api/events/{$event->slug}/raffle/draws";

    $first = $this->actingAs($owner)->postJson($url)->assertCreated();
    $this->postJson("{$url}/{$first->json('data.draw.id')}/confirm")->assertOk();
    $second = $this->postJson($url)->assertCreated();

    expect($second->json('data.attendee.registration_id'))->not->toBe($first->json('data.attendee.registration_id'));
});

test('a winner remains eligible in a different event', function () {
    $owner = User::factory()->create();
    $attendee = Attendee::create(['first_name' => 'Jamie', 'last_name' => 'Rivera', 'email' => 'jamie@example.com', 'email_normalized' => 'jamie@example.com']);
    [$first] = raffleRegistration($owner, $attendee, 'raffle-won-first');
    [$second, $secondRegistration] = raffleRegistration($owner, $attendee, 'raffle-won-second');
    $draw = $this->actingAs($owner)->postJson("/api/events/{$first->slug}/raffle/draws")->assertCreated()->json('data.draw.id');
    $this->postJson("/api/events/{$first->slug}/raffle/draws/{$draw}/confirm")->assertOk();

    $this->postJson("/api/events/{$second->slug}/raffle/draws")->assertCreated()
        ->assertJsonPath('data.attendee.registration_id', $secondRegistration->id);
});

test('cross event mutations return 404 without changing the draw', function () {
    $owner = User::factory()->create();
    $attendee = Attendee::create(['first_name' => 'Jamie', 'last_name' => 'Rivera', 'email' => 'jamie@example.com', 'email_normalized' => 'jamie@example.com']);
    [$first] = raffleRegistration($owner, $attendee, 'raffle-cross-first');
    [$second] = raffleRegistration($owner, $attendee, 'raffle-cross-second');
    $draw = $this->actingAs($owner)->postJson("/api/events/{$first->slug}/raffle/draws")->assertCreated()->json('data.draw.id');
    $foreignUrl = "/api/events/{$second->slug}/raffle/draws/{$draw}";

    $this->postJson("{$foreignUrl}/confirm")->assertNotFound();
    $this->deleteJson($foreignUrl)->assertNotFound();
    $this->assertDatabaseHas('raffle_draws', ['id' => $draw, 'status' => 'pending']);
    $this->assertDatabaseCount('raffle_winners', 0);
});

test('draws paired with another event registration cannot be confirmed or cancelled', function () {
    $owner = User::factory()->create();
    $attendee = Attendee::create(['first_name' => 'Jamie', 'last_name' => 'Rivera', 'email' => 'jamie@example.com', 'email_normalized' => 'jamie@example.com']);
    [$first] = raffleRegistration($owner, $attendee, 'raffle-pair-first');
    [$second, $otherRegistration] = raffleRegistration($owner, $attendee, 'raffle-pair-second');
    $draw = $first->raffleDraws()->create(['registration_id' => $otherRegistration->id, 'status' => 'pending', 'selected_at' => now(), 'expires_at' => now()->addMinutes(10)]);
    $url = "/api/events/{$first->slug}/raffle/draws/{$draw->id}";

    $this->actingAs($owner)->postJson("{$url}/confirm")->assertNotFound();
    $this->deleteJson($url)->assertNotFound();
    $this->assertDatabaseHas('raffle_draws', ['id' => $draw->id, 'status' => 'pending']);
    $this->assertDatabaseCount('raffle_winners', 0);
});

test('expired draws cannot be confirmed', function () {
    $this->travelTo(Carbon::parse('2026-09-28 10:00:00'));
    $owner = User::factory()->create();
    $attendee = Attendee::create(['first_name' => 'Jamie', 'last_name' => 'Rivera', 'email' => 'jamie@example.com', 'email_normalized' => 'jamie@example.com']);
    [$event] = raffleRegistration($owner, $attendee, 'raffle-expired-confirm');
    $draw = $this->actingAs($owner)->postJson("/api/events/{$event->slug}/raffle/draws")->assertCreated()->json('data.draw.id');

    $this->travelTo(Carbon::parse('2026-09-28 10:10:00'));
    $this->postJson("/api/events/{$event->slug}/raffle/draws/{$draw}/confirm")->assertStatus(409);
    $this->assertDatabaseCount('raffle_winners', 0);
});

test('exhausted raffles return a safe conflict response', function () {
    $owner = User::factory()->create();
    $attendee = Attendee::create(['first_name' => 'Jamie', 'last_name' => 'Rivera', 'email' => 'jamie@example.com', 'email_normalized' => 'jamie@example.com']);
    [$event] = raffleRegistration($owner, $attendee, 'raffle-exhausted');
    $draw = $this->actingAs($owner)->postJson("/api/events/{$event->slug}/raffle/draws")->assertCreated()->json('data.draw.id');
    $this->postJson("/api/events/{$event->slug}/raffle/draws/{$draw}/confirm")->assertOk();

    $response = $this->postJson("/api/events/{$event->slug}/raffle/draws")->assertStatus(409);
    expect($response->getContent())->not->toContain('SQLSTATE', 'Exception', 'QueryException');
});

test('only the owner admin can mutate raffle draws', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $scanner = User::factory()->scanner()->create();
    $attendee = Attendee::create(['first_name' => 'Jamie', 'last_name' => 'Rivera', 'email' => 'jamie@example.com', 'email_normalized' => 'jamie@example.com']);
    [$event] = raffleRegistration($owner, $attendee, 'raffle-write-access');
    $url = "/api/events/{$event->slug}/raffle/draws";
    $draw = $this->actingAs($owner)->postJson($url)->assertCreated()->json('data.draw.id');

    $this->actingAs($other)->postJson($url)->assertNotFound();
    $this->postJson("{$url}/{$draw}/confirm")->assertNotFound();
    $this->deleteJson("{$url}/{$draw}")->assertNotFound();
    $this->actingAs($scanner)->postJson($url)->assertForbidden();
    $this->postJson("{$url}/{$draw}/confirm")->assertForbidden();
    $this->deleteJson("{$url}/{$draw}")->assertForbidden();
    $this->assertDatabaseHas('raffle_draws', ['id' => $draw, 'status' => 'pending']);
});
