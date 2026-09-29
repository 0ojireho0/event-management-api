<?php

use App\Models\Attendee;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

test('an event persists ordered contestants and one vote per registration per subject', function () {
    $owner = User::factory()->create();
    $event = Event::create([
        'created_by' => $owner->id,
        'title' => 'Annual Gala',
        'slug' => 'annual-gala',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(2),
    ]);
    $form = $event->registrationForms()->create(['title' => 'Registration']);
    $attendee = Attendee::create([
        'first_name' => 'Jamie',
        'last_name' => 'Rivera',
        'email' => 'jamie@example.com',
        'email_normalized' => 'jamie@example.com',
    ]);
    $registration = $event->registrations()->create([
        'registration_form_id' => $form->id,
        'attendee_id' => $attendee->id,
        'registration_code' => 'REG-VOTE01',
        'registered_at' => now(),
    ]);

    $firstSubject = $event->votingSubjects()->create([
        'slug' => hash('sha256', 'best-performer'),
        'title' => 'Best Performer',
        'status' => 'draft',
    ]);
    $secondSubject = $event->votingSubjects()->create([
        'slug' => hash('sha256', 'best-costume'),
        'title' => 'Best Costume',
        'status' => 'active',
    ]);
    $later = $firstSubject->contestants()->create(['name' => 'Taylor', 'display_order' => 2]);
    $earlier = $firstSubject->contestants()->create(['name' => 'Jordan', 'display_order' => 1]);
    $defaultOrder = $secondSubject->contestants()->create(['name' => 'Sam']);
    $firstVote = $firstSubject->votes()->create([
        'voting_contestant_id' => $earlier->id,
        'registration_id' => $registration->id,
    ]);
    $secondVote = $secondSubject->votes()->create([
        'voting_contestant_id' => $defaultOrder->id,
        'registration_id' => $registration->id,
    ]);

    expect($event->votingSubjects()->orderBy('id')->pluck('id')->all())->toBe([$firstSubject->id, $secondSubject->id])
        ->and($firstSubject->event->is($event))->toBeTrue()
        ->and($firstSubject->getRouteKey())->toBe(hash('sha256', 'best-performer'))
        ->and($firstSubject->contestants()->orderBy('display_order')->pluck('id')->all())->toBe([$earlier->id, $later->id])
        ->and($defaultOrder->fresh()->display_order)->toBe(0)
        ->and($earlier->subject->is($firstSubject))->toBeTrue()
        ->and($firstSubject->votes()->pluck('id')->all())->toBe([$firstVote->id])
        ->and($earlier->votes()->pluck('id')->all())->toBe([$firstVote->id])
        ->and($registration->votingVotes()->orderBy('id')->pluck('id')->all())->toBe([$firstVote->id, $secondVote->id])
        ->and($firstVote->subject->is($firstSubject))->toBeTrue()
        ->and($firstVote->contestant->is($earlier))->toBeTrue()
        ->and($firstVote->registration->is($registration))->toBeTrue();

    expect(fn () => $firstSubject->votes()->create([
        'voting_contestant_id' => $later->id,
        'registration_id' => $registration->id,
    ]))->toThrow(UniqueConstraintViolationException::class);

    $firstSubject->delete();

    $this->assertDatabaseMissing('voting_contestants', ['id' => $earlier->id]);
    $this->assertDatabaseMissing('voting_contestants', ['id' => $later->id]);
    $this->assertDatabaseMissing('voting_votes', ['id' => $firstVote->id]);
    $this->assertDatabaseHas('voting_votes', ['id' => $secondVote->id]);
});
