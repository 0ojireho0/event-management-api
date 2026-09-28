<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\RaffleDraw;
use App\Models\RaffleWinner;
use App\Models\Registration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RaffleController extends Controller
{
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
