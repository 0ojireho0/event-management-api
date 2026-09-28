<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\RaffleDraw;
use App\Models\RaffleWinner;
use App\Models\Registration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RaffleController extends Controller
{
    public const PENDING_MINUTES = 10;

    public function show(Request $request, Event $event): JsonResponse
    {
        abort_unless($event->created_by === $request->user()->id, 404);

        $eligible = $event->registrations()
            ->select(['id', 'event_id', 'attendee_id'])
            ->where('status', 'confirmed')
            ->whereDoesntHave('raffleWinner', fn ($query) => $query->where('event_id', $event->id))
            ->whereDoesntHave('raffleDraws', fn ($query) => $query
                ->where('event_id', $event->id)
                ->where('status', RaffleDraw::STATUS_PENDING)
                ->where('expires_at', '>', now()))
            ->with('attendee:id,first_name,last_name,email')
            ->orderBy('id')
            ->get()
            ->map(fn (Registration $registration) => $this->attendeeData($registration));

        $pending = $event->raffleDraws()
            ->select(['id', 'event_id', 'registration_id', 'selected_at', 'expires_at'])
            ->where('status', RaffleDraw::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->whereHas('registration', fn ($query) => $query->where('event_id', $event->id))
            ->with('registration:id,event_id,attendee_id', 'registration.attendee:id,first_name,last_name,email')
            ->latest('id')
            ->first();

        $winners = $event->raffleWinners()
            ->select(['id', 'event_id', 'registration_id', 'won_at'])
            ->whereHas('registration', fn ($query) => $query->where('event_id', $event->id))
            ->with('registration:id,event_id,attendee_id', 'registration.attendee:id,first_name,last_name,email')
            ->orderByDesc('won_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (RaffleWinner $winner) => [
                'id' => $winner->id,
                ...$this->attendeeData($winner->registration),
                'won_at' => $winner->won_at->toISOString(),
            ]);

        return response()->json(['data' => [
            'event' => $event->only(['id', 'title', 'slug']),
            'eligible_attendees' => $eligible,
            'eligible_count' => $eligible->count(),
            'pending_draw' => $pending ? [
                'id' => $pending->id,
                ...$this->attendeeData($pending->registration),
                'selected_at' => $pending->selected_at->toISOString(),
                'expires_at' => $pending->expires_at->toISOString(),
            ] : null,
            'winners' => $winners,
        ]]);
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        abort_unless($event->created_by === $request->user()->id, 404);

        return DB::transaction(function () use ($event, $request): JsonResponse {
            Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
            $now = now();

            $event->raffleDraws()
                ->where('status', RaffleDraw::STATUS_PENDING)
                ->where('expires_at', '<=', $now)
                ->whereHas('registration', fn ($query) => $query->where('event_id', $event->id))
                ->update(['status' => RaffleDraw::STATUS_CANCELLED, 'cancelled_at' => $now]);

            $pending = $event->raffleDraws()
                ->where('status', RaffleDraw::STATUS_PENDING)
                ->where('expires_at', '>', $now)
                ->whereHas('registration', fn ($query) => $query->where('event_id', $event->id))
                ->with('registration.attendee')
                ->first();

            if ($pending) {
                return response()->json(['data' => $this->drawResponse($pending)]);
            }

            $eligibleIds = $event->registrations()
                ->where('status', 'confirmed')
                ->whereDoesntHave('raffleWinner', fn ($query) => $query->where('event_id', $event->id))
                ->whereDoesntHave('raffleDraws', fn ($query) => $query
                    ->where('event_id', $event->id)
                    ->where('status', RaffleDraw::STATUS_PENDING)
                    ->where('expires_at', '>', $now))
                ->pluck('id');

            if ($eligibleIds->isEmpty()) {
                return response()->json(['message' => 'No eligible attendees remain.'], 409);
            }

            $registration = $event->registrations()
                ->whereKey($eligibleIds[random_int(0, $eligibleIds->count() - 1)])
                ->where('status', 'confirmed')
                ->with('attendee')
                ->firstOrFail();
            $draw = $event->raffleDraws()->create([
                'registration_id' => $registration->id,
                'selected_by' => $request->user()->id,
                'status' => RaffleDraw::STATUS_PENDING,
                'selected_at' => $now,
                'expires_at' => $now->copy()->addMinutes(self::PENDING_MINUTES),
            ]);
            $draw->setRelation('registration', $registration);

            return response()->json(['data' => $this->drawResponse($draw)], 201);
        });
    }

    public function confirm(Request $request, Event $event, RaffleDraw $draw): JsonResponse
    {
        abort_unless($event->created_by === $request->user()->id, 404);

        return DB::transaction(function () use ($event, $draw, $request): JsonResponse {
            Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
            $draw = $event->raffleDraws()->whereKey($draw->id)->lockForUpdate()->firstOrFail();
            $registration = $event->registrations()->whereKey($draw->registration_id)->lockForUpdate()->with('attendee')->firstOrFail();
            $now = now();

            if ($draw->status !== RaffleDraw::STATUS_PENDING || $draw->expires_at->lte($now)) {
                return response()->json(['message' => 'Draw is no longer pending.'], 409);
            }

            if ($registration->status !== 'confirmed' || $event->raffleWinners()->where('registration_id', $registration->id)->exists()) {
                return response()->json(['message' => 'Registration is no longer eligible.'], 409);
            }

            $winner = $event->raffleWinners()->create([
                'registration_id' => $registration->id,
                'raffle_draw_id' => $draw->id,
                'confirmed_by' => $request->user()->id,
                'won_at' => $now,
            ]);
            $draw->update(['status' => RaffleDraw::STATUS_CONFIRMED, 'confirmed_at' => $now]);
            $draw->setRelation('registration', $registration);

            return response()->json(['data' => [
                ...$this->drawResponse($draw),
                'winner' => [
                    'id' => $winner->id,
                    ...$this->attendeeData($registration),
                    'won_at' => $winner->won_at->toISOString(),
                ],
            ]]);
        });
    }

    public function destroy(Request $request, Event $event, RaffleDraw $draw): JsonResponse
    {
        abort_unless($event->created_by === $request->user()->id, 404);

        return DB::transaction(function () use ($event, $draw): JsonResponse {
            Event::whereKey($event->id)->lockForUpdate()->firstOrFail();
            $draw = $event->raffleDraws()->whereKey($draw->id)->lockForUpdate()->firstOrFail();
            $registration = $event->registrations()->whereKey($draw->registration_id)->lockForUpdate()->with('attendee')->firstOrFail();

            if ($draw->status !== RaffleDraw::STATUS_PENDING) {
                return response()->json(['message' => 'Draw is no longer pending.'], 409);
            }

            $draw->update(['status' => RaffleDraw::STATUS_CANCELLED, 'cancelled_at' => now()]);
            $draw->setRelation('registration', $registration);

            return response()->json(['data' => $this->drawResponse($draw)]);
        });
    }

    private function drawResponse(RaffleDraw $draw): array
    {
        return [
            'draw' => [
                'id' => $draw->id,
                'status' => $draw->status,
                'selected_at' => $draw->selected_at->toISOString(),
                'expires_at' => $draw->expires_at->toISOString(),
                'confirmed_at' => $draw->confirmed_at?->toISOString(),
                'cancelled_at' => $draw->cancelled_at?->toISOString(),
            ],
            'attendee' => $this->attendeeData($draw->registration),
        ];
    }

    private function attendeeData(Registration $registration): array
    {
        [$local, $domain] = explode('@', $registration->attendee->email, 2);

        return [
            'registration_id' => $registration->id,
            'first_name' => $registration->attendee->first_name,
            'last_name' => $registration->attendee->last_name,
            'masked_email' => Str::substr($local, 0, 1).'***@'.$domain,
        ];
    }
}
