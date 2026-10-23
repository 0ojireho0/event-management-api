<?php

use App\Events\NominationStatisticsUpdated;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Models\VotingSubject;
use App\Services\VotingStatistics;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function broadcastSubject(string $mode = 'nomination', string $status = 'active', ?User $owner = null): VotingSubject
{
    $event = Event::create([
        'created_by' => ($owner ?? User::factory()->create())->id,
        'title' => 'Nomination Gala', 'slug' => Str::uuid()->toString(),
        'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2),
    ]);

    return $event->votingSubjects()->create([
        'title' => 'Best Teammate', 'slug' => hash('sha256', Str::uuid()),
        'mode' => $mode, 'status' => $status, 'top_n' => $mode === 'nomination' ? 2 : null,
    ]);
}

function broadcastRegistration(VotingSubject $subject, string $status = 'confirmed'): Registration
{
    $email = Str::uuid().'@example.com';
    $attendee = Attendee::create([
        'first_name' => 'Private', 'last_name' => 'Voter', 'email' => $email, 'email_normalized' => $email,
    ]);

    return $subject->event->registrations()->create([
        'registration_form_id' => $subject->event->registrationForms()->firstOrCreate(['title' => 'Registration'])->id,
        'attendee_id' => $attendee->id, 'registration_code' => Str::upper(Str::random(16)),
        'status' => $status, 'registered_at' => now(),
    ]);
}

function broadcastVote(VotingSubject $subject, Registration $registration, int $contestantId)
{
    return test()->postJson("/api/voting/{$subject->slug}/votes", [
        'registration_code' => $registration->registration_code, 'contestant_id' => $contestantId,
    ]);
}

function queuedNominationBroadcast(): BroadcastEvent
{
    $payload = json_decode(DB::table('jobs')->sole()->payload, true);

    return unserialize($payload['data']['command']);
}

function useReverbChannelAuthentication(): void
{
    // The test app boots with the null broadcaster. Preserve the actual registered callbacks
    // when switching to the real Reverb signing driver; no socket server is needed for auth.
    $channels = Broadcast::getChannels();
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-public-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    foreach ($channels as $pattern => $callback) {
        Broadcast::channel($pattern, $callback);
    }
}

beforeEach(function () {
    config(['queue.default' => 'database']);
});

test('canonical nomination statistics contain exact ranks shares and confirmed participation without voter data', function () {
    $subject = broadcastSubject();
    $alex = $subject->contestants()->create(['name' => 'Alex', 'display_order' => 2]);
    $bea = $subject->contestants()->create(['name' => 'Bea', 'display_order' => 0]);
    $cam = $subject->contestants()->create(['name' => 'Cam', 'display_order' => 1]);
    $dee = $subject->contestants()->create(['name' => 'Dee', 'display_order' => 3]);
    foreach ([$alex, $bea, $alex, $bea, $cam] as $contestant) {
        $subject->votes()->create([
            'registration_id' => broadcastRegistration($subject)->id, 'voting_contestant_id' => $contestant->id,
        ]);
    }
    broadcastRegistration($subject);
    broadcastRegistration($subject, 'cancelled');
    $expected = [
        'event' => ['id' => $subject->event_id, 'slug' => $subject->event->slug, 'title' => 'Nomination Gala'],
        'subject' => ['id' => $subject->id, 'slug' => $subject->slug, 'title' => 'Best Teammate', 'status' => 'active'],
        'total_votes' => 5, 'total_registrations' => 6, 'participation_percentage' => 83.33,
        'contestants' => [
            ['id' => $bea->id, 'name' => 'Bea', 'display_order' => 0, 'votes' => 2, 'rank' => 1, 'percentage' => 40],
            ['id' => $alex->id, 'name' => 'Alex', 'display_order' => 2, 'votes' => 2, 'rank' => 1, 'percentage' => 40],
            ['id' => $cam->id, 'name' => 'Cam', 'display_order' => 1, 'votes' => 1, 'rank' => 3, 'percentage' => 20],
            ['id' => $dee->id, 'name' => 'Dee', 'display_order' => 3, 'votes' => 0, 'rank' => 4, 'percentage' => 0],
        ],
    ];

    expect(app(VotingStatistics::class)->forSubject($subject))->toEqual($expected);
    $event = new NominationStatisticsUpdated($subject);
    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->toBeInstanceOf(ShouldDispatchAfterCommit::class)->toBeInstanceOf(ShouldRescue::class)
        ->and($event->broadcastWith())->toEqual([
            'event_id' => $subject->event_id,
            'subject_id' => $subject->id,
            'status' => 'active',
            'total_votes' => 5,
            'total_registrations' => 6,
            'contestant_votes' => [2, 1, 2, 0],
        ]);
    $this->actingAs(User::find($subject->event->created_by))
        ->getJson("/api/events/{$subject->event->slug}/voting-subjects/{$subject->slug}/results")
        ->assertOk()->assertExactJson($expected);
});

test('a successful nomination vote queues its private aggregate update only after commit', function () {
    $subject = broadcastSubject();
    $contestant = $subject->contestants()->create(['name' => 'Nominee']);
    $registration = broadcastRegistration($subject);
    DB::beginTransaction();
    broadcastVote($subject, $registration, $contestant->id)
        ->assertCreated()->assertExactJson(['message' => 'Your vote has been recorded.']);
    $this->assertDatabaseCount('jobs', 0);
    DB::commit();

    $this->assertDatabaseCount('jobs', 1);
    $job = queuedNominationBroadcast();
    expect($job->event)->toBeInstanceOf(NominationStatisticsUpdated::class)
        ->and((string) $job->event->broadcastOn()[0])->toBe("private-events.{$subject->event_id}.nominations.{$subject->id}")
        ->and($job->event->broadcastWith()['total_votes'])->toBe(1);
    $this->getJson("/api/voting/{$subject->slug}")->assertOk()->assertExactJson([
        'title' => 'Best Teammate', 'contestants' => [['id' => $contestant->id, 'name' => 'Nominee']],
    ]);
});

test('the largest nomination broadcast remains below Reverb default and excludes nominee names', function () {
    $subject = broadcastSubject();
    $longName = str_repeat('N', 511);
    $subject->contestants()->createMany(array_map(
        fn ($order) => ['name' => $longName, 'display_order' => $order],
        range(0, 249),
    ));

    $payload = (new NominationStatisticsUpdated($subject))->broadcastWith();
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $largestCounts = $payload;
    $largestCounts['contestant_votes'] = array_fill(0, 250, 36_000_000_000_000_000);
    $largestCounts['total_votes'] = 9_000_000_000_000_000_000;
    $largestCounts['total_registrations'] = PHP_INT_MAX;
    $largestCountsJson = json_encode($largestCounts, JSON_THROW_ON_ERROR);

    expect($payload['contestant_votes'])->toHaveCount(250)
        ->and($payload['contestant_votes'])->toBe(array_fill(0, 250, 0))
        ->and(array_keys($payload))->toBe(['event_id', 'subject_id', 'status', 'total_votes', 'total_registrations', 'contestant_votes'])
        ->and(strlen($json))->toBeLessThan(10_000)
        ->and(strlen($largestCountsJson))->toBeLessThan(10_000)
        ->and($json)->not->toContain($longName);
});

test('rolling back a saved nomination vote also discards its broadcast', function () {
    $subject = broadcastSubject();
    $contestant = $subject->contestants()->create(['name' => 'Nominee']);
    $registration = broadcastRegistration($subject);
    DB::beginTransaction();
    broadcastVote($subject, $registration, $contestant->id)->assertCreated();
    DB::rollBack();
    $this->assertDatabaseCount('voting_votes', 0);
    $this->assertDatabaseCount('jobs', 0);
});

test('manual votes never queue nomination updates', function () {
    $subject = broadcastSubject('manual');
    $contestant = $subject->contestants()->create(['name' => 'Contestant']);
    broadcastVote($subject, broadcastRegistration($subject), $contestant->id)->assertCreated();
    $this->assertDatabaseCount('voting_votes', 1);
    $this->assertDatabaseCount('jobs', 0);
});

test('invalid duplicate and closed nomination votes never add broadcast jobs', function () {
    $subject = broadcastSubject();
    $contestant = $subject->contestants()->create(['name' => 'Nominee']);
    $registration = broadcastRegistration($subject);
    broadcastVote($subject, broadcastRegistration($subject, 'cancelled'), $contestant->id)->assertUnprocessable();
    broadcastVote($subject, $registration, $contestant->id + 1000)->assertUnprocessable();
    $this->assertDatabaseCount('jobs', 0);
    broadcastVote($subject, $registration, $contestant->id)->assertCreated();
    broadcastVote($subject, $registration, $contestant->id)->assertConflict();
    $subject->update(['status' => 'closed']);
    broadcastVote($subject, broadcastRegistration($subject), $contestant->id)->assertNotFound();
    $this->assertDatabaseCount('voting_votes', 1);
    $this->assertDatabaseCount('jobs', 1);
});

test('a queued update is suppressed if the nomination closes before the worker handles it', function () {
    $subject = broadcastSubject();
    $contestant = $subject->contestants()->create(['name' => 'Nominee']);
    broadcastVote($subject, broadcastRegistration($subject), $contestant->id)->assertCreated();
    $job = queuedNominationBroadcast();
    expect($job->event->broadcastWhen())->toBeTrue();
    $subject->update(['status' => 'closed']);
    expect($job->event->broadcastWhen())->toBeFalse();
    $manager = Mockery::mock(Factory::class);
    $manager->shouldNotReceive('connection');
    $job->handle($manager);
});

test('a later transport failure cannot undo the successful saved nomination vote', function () {
    $subject = broadcastSubject();
    $contestant = $subject->contestants()->create(['name' => 'Nominee']);
    broadcastVote($subject, broadcastRegistration($subject), $contestant->id)->assertCreated();
    $job = queuedNominationBroadcast();
    $manager = Mockery::mock(Factory::class);
    $manager->shouldReceive('connection')->once()->andThrow(new RuntimeException('WebSocket unavailable'));
    expect(fn () => $job->handle($manager))->toThrow(RuntimeException::class, 'WebSocket unavailable');
    $this->assertDatabaseCount('voting_votes', 1);
});

test('broadcast enqueue failures are rescued after saving the vote', function () {
    $subject = broadcastSubject();
    $contestant = $subject->contestants()->create(['name' => 'Nominee']);
    // The real database queue attempts to write a missing jobs table.
    config(['queue.connections.database.table' => 'missing_broadcast_jobs']);
    broadcastVote($subject, broadcastRegistration($subject), $contestant->id)->assertCreated();
    $this->assertDatabaseCount('voting_votes', 1);
});

test('private channel authentication admits only the active nomination event owner', function () {
    useReverbChannelAuthentication();
    $owner = User::factory()->create();
    $subject = broadcastSubject(owner: $owner);
    $payload = ['socket_id' => '123.456', 'channel_name' => "private-events.{$subject->event_id}.nominations.{$subject->id}"];
    $this->postJson('/api/broadcasting/auth', $payload)->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson('/api/broadcasting/auth', $payload)->assertForbidden();
    $this->actingAs(User::factory()->scanner()->create())->postJson('/api/broadcasting/auth', $payload)->assertForbidden();
    $this->actingAs($owner)->postJson('/api/broadcasting/auth', $payload)->assertOk()->assertJsonStructure(['auth']);
    $subject->update(['status' => 'closed']);
    $this->postJson('/api/broadcasting/auth', $payload)->assertForbidden();
});

test('private channel authentication rejects drafts manual subjects and mismatched events even for their owner', function () {
    useReverbChannelAuthentication();
    $owner = User::factory()->create();
    $draft = broadcastSubject(status: 'draft', owner: $owner);
    $manual = broadcastSubject(mode: 'manual', owner: $owner);
    $active = broadcastSubject(owner: $owner);
    $this->actingAs($owner);
    foreach ([[$draft->event_id, $draft->id], [$manual->event_id, $manual->id], [$draft->event_id, $active->id], [$active->event_id, 99999]] as [$eventId, $subjectId]) {
        $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '123.456', 'channel_name' => "private-events.{$eventId}.nominations.{$subjectId}",
        ])->assertForbidden();
    }
    $active->event->update(['created_by' => User::factory()->scanner()->create()->id]);
    $this->actingAs(User::find($active->event->created_by))->postJson('/api/broadcasting/auth', [
        'socket_id' => '123.456', 'channel_name' => "private-events.{$active->event_id}.nominations.{$active->id}",
    ])->assertForbidden();
});
