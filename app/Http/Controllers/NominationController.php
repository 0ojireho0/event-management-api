<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\NomineeSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NominationController extends Controller
{
    public function candidates(Request $request, Event $event, NomineeSearchService $search): JsonResponse
    {
        abort_unless($event->created_by === $request->user()->id, 404);

        $data = $request->validate(['q' => ['nullable', 'string', 'max:511']]);

        return response()->json(['data' => $search->search($event, $data['q'] ?? '')]);
    }
}
