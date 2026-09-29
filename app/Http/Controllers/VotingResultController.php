<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\VotingContestant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VotingResultController extends Controller
{
    public function show(Request $request, Event $event, string $subject): JsonResponse
    {
        abort_unless($event->created_by === $request->user()->id, 404);

        $record = $event->votingSubjects()->where('slug', $subject)->firstOrFail();
        $totalVotes = $record->votes()->count();
        $totalRegistrations = $event->registrations()->where('status', 'confirmed')->count();
        $contestants = $record->contestants()
            ->select(['id', 'voting_subject_id', 'name', 'display_order'])
            ->withCount('votes')
            ->orderByDesc('votes_count')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->map(fn (VotingContestant $contestant): array => [
                'id' => $contestant->id,
                'name' => $contestant->name,
                'display_order' => $contestant->display_order,
                'votes' => $contestant->votes_count,
                'percentage' => $totalVotes === 0 ? 0 : round($contestant->votes_count * 100 / $totalVotes, 2),
            ]);

        return response()->json([
            'event' => $event->only(['id', 'slug', 'title']),
            'subject' => $record->only(['id', 'slug', 'title', 'status']),
            'total_votes' => $totalVotes,
            'total_registrations' => $totalRegistrations,
            'participation_percentage' => $totalRegistrations === 0 ? 0 : round($totalVotes * 100 / $totalRegistrations, 2),
            'contestants' => $contestants,
        ]);
    }
}
