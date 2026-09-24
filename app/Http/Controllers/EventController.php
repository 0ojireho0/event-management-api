<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEventRequest;
use App\Models\EmailInvitation;
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

    public function registrationExport(Request $request, Event $event): JsonResponse
    {
        $this->ensureOwner($request, $event);

        $registrations = $event->registrations()
            ->with(['attendee', 'answers.field', 'checkIns'])
            ->latest('registered_at')
            ->get();

        return response()->json(['data' => $registrations]);
    }

    public function sendInvitation(Request $request, Event $event): JsonResponse
    {
        $this->ensureOwner($request, $event);

        $emailInput = $request->input('emails', []);

        if (is_array($emailInput)) {
            $request->merge([
                'emails' => collect($emailInput)
                    ->map(fn ($email) => is_string($email) ? strtolower(trim($email)) : $email)
                    ->unique()
                    ->values()
                    ->all(),
            ]);
        }

        $validated = $request->validate([
            'emails' => ['required', 'array', 'min:1', 'max:100'],
            'emails.*' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);
        $registrationUrl = rtrim((string) config('app.frontend_url'), '/').'/register/'.$event->slug;
        $invitations = collect();

        foreach ($validated['emails'] as $email) {
            $invitation = $event->emailInvitations()->create([
                'email' => $email,
                'status' => 'failed',
            ]);

            try {
                Mail::raw(
                    "You are invited to register for {$event->title}.\n\nRegistration link: {$registrationUrl}",
                    fn ($message) => $message
                        ->to($email)
                        ->subject('Invitation: '.$event->title),
                );

                $invitation->update(['status' => 'sent']);
            } catch (\Throwable) {
                // The audit log intentionally stores only the delivery status.
            }

            $invitations->push($invitation->refresh());
        }

        $sentCount = $invitations->where('status', 'sent')->count();
        $failedCount = $invitations->where('status', 'failed')->count();

        return response()->json([
            'data' => $invitations->map(fn (EmailInvitation $invitation) => $this->invitationData($event, $invitation))->values(),
            'message' => $failedCount === 0
                ? ($sentCount === 1 ? 'Invitation sent successfully.' : "{$sentCount} invitations sent successfully.")
                : "{$sentCount} sent, {$failedCount} failed.",
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'registration_url' => $registrationUrl,
        ]);
    }

    public function invitations(Request $request, Event $event): JsonResponse
    {
        $this->ensureOwner($request, $event);

        $invitations = $event->emailInvitations()
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (EmailInvitation $invitation) => $this->invitationData($event, $invitation));

        return response()->json(['data' => $invitations]);
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

    private function invitationData(Event $event, EmailInvitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'status' => $invitation->status,
            'created_at' => $invitation->created_at,
            'event' => [
                'slug' => $event->slug,
                'title' => $event->title,
            ],
        ];
    }

    private function uniqueHashedSlug(): string
    {
        do {
            $slug = hash('sha256', Str::random(64));
        } while (Event::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }
}
