<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEventRequest;
use App\Models\Event;
use App\Models\Registration;
use App\Models\RegistrationForm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $events = Event::query()
            ->where('created_by', $request->user()->id)
            ->with(['activeRegistrationForm.fields.options'])
            ->withCount(['registrations', 'acceptedCheckIns'])
            ->latest('starts_at')
            ->get();

        return response()->json(['data' => $events]);
    }

    public function store(StoreEventRequest $request): JsonResponse
    {
        $event = DB::transaction(function () use ($request): Event {
            $data = $request->safe()->except('registration_form');
            $data['created_by'] = $request->user()->id;
            $data['slug'] = $this->uniqueHashedSlug();

            $event = Event::create($data);
            $form = $event->registrationForms()->create([
                'version' => 1,
                'title' => $event->title.' Registration',
                'description' => $event->description,
                'is_active' => true,
                'published_at' => $event->status === 'draft' ? null : now(),
            ]);

            foreach ($request->validated('registration_form') as $position => $fieldData) {
                $options = $fieldData['options'];
                unset($fieldData['options'], $fieldData['required']);

                $field = $form->fields()->create([
                    ...$fieldData,
                    'is_required' => $request->input("registration_form.$position.required"),
                    'position' => $position,
                ]);

                foreach ($options as $optionPosition => $option) {
                    $field->options()->create([
                        'label' => $option,
                        'value' => (Str::slug($option) ?: 'option').'-'.($optionPosition + 1),
                        'position' => $optionPosition,
                    ]);
                }
            }

            return $event;
        });

        return response()->json([
            'data' => $this->loadEvent($event),
            'message' => 'Event published successfully.',
        ], 201);
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        $this->ensureOwner($request, $event);

        return response()->json(['data' => $this->loadEvent($event)]);
    }

    public function update(StoreEventRequest $request, Event $event): JsonResponse
    {
        $this->ensureOwner($request, $event);

        DB::transaction(function () use ($request, $event): void {
            $event->update($request->safe()->except('registration_form'));
            $event->registrationForms()->update(['is_active' => false]);

            $form = $event->registrationForms()->create([
                'version' => ((int) $event->registrationForms()->max('version')) + 1,
                'title' => $event->title.' Registration',
                'description' => $event->description,
                'is_active' => true,
                'published_at' => $event->status === 'draft' ? null : now(),
            ]);

            $this->createFormFields($form, $request->validated('registration_form'));
        });

        return response()->json([
            'data' => $this->loadEvent($event->refresh()),
            'message' => 'Event updated successfully.',
        ]);
    }

    public function registrations(Request $request, Event $event): JsonResponse
    {
        $this->ensureOwner($request, $event);

        $registrations = $event->registrations()
            ->with(['attendee', 'answers.field', 'checkIns'])
            ->latest('registered_at')
            ->paginate(50);

        return response()->json($registrations);
    }

    public function sendInvitation(Request $request, Event $event): JsonResponse
    {
        $this->ensureOwner($request, $event);
        $validated = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        $registrationUrl = rtrim((string) config('app.frontend_url'), '/').'/register/'.$event->slug;

        Mail::raw(
            "You are invited to register for {$event->title}.\n\nRegistration link: {$registrationUrl}",
            fn ($message) => $message
                ->to($validated['email'])
                ->subject('Invitation: '.$event->title),
        );

        return response()->json([
            'message' => 'Invitation sent successfully.',
            'registration_url' => $registrationUrl,
        ]);
    }

    public function checkIn(Request $request, Event $event): JsonResponse
    {
        $this->ensureOwner($request, $event);
        $validated = $request->validate([
            'registration_code' => ['required', 'string'],
            'gate' => ['nullable', 'string', 'max:100'],
            'device_id' => ['nullable', 'string', 'max:100'],
        ]);

        return DB::transaction(function () use ($event, $request, $validated): JsonResponse {
            $registration = Registration::query()
                ->where('event_id', $event->id)
                ->where('registration_code', $validated['registration_code'])
                ->lockForUpdate()
                ->firstOrFail();

            $duplicate = $registration->checkIns()->where('result', 'accepted')->exists();
            $checkIn = $registration->checkIns()->create([
                'checked_in_by' => $request->user()->id,
                'checked_in_at' => now(),
                'gate' => $validated['gate'] ?? null,
                'device_id' => $validated['device_id'] ?? null,
                'result' => $duplicate ? 'duplicate' : 'accepted',
            ]);

            return response()->json([
                'data' => $checkIn->load('registration.attendee'),
                'message' => $duplicate ? 'Attendee was already checked in.' : 'Check-in accepted.',
            ], $duplicate ? 409 : 201);
        });
    }

    private function loadEvent(Event $event): Event
    {
        return $event->load(['activeRegistrationForm.fields.options'])
            ->loadCount(['registrations', 'acceptedCheckIns']);
    }

    private function createFormFields(RegistrationForm $form, array $fields): void
    {
        foreach ($fields as $position => $fieldData) {
            $options = $fieldData['options'];
            $required = $fieldData['required'];
            unset($fieldData['options'], $fieldData['required']);

            $field = $form->fields()->create([
                ...$fieldData,
                'is_required' => $required,
                'position' => $position,
            ]);

            foreach ($options as $optionPosition => $option) {
                $field->options()->create([
                    'label' => $option,
                    'value' => (Str::slug($option) ?: 'option').'-'.($optionPosition + 1),
                    'position' => $optionPosition,
                ]);
            }
        }
    }

    private function ensureOwner(Request $request, Event $event): void
    {
        abort_unless($event->created_by === $request->user()->id, 404);
    }

    private function uniqueHashedSlug(): string
    {
        do {
            $slug = hash('sha256', Str::random(64));
        } while (Event::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }
}
