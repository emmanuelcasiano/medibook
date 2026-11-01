<?php

declare(strict_types=1);

namespace App\Http\Controllers\Patient;

use App\Actions\Intake\SaveIntake;
use App\Enums\IntakeDuration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Intake\SaveIntakeRequest;
use App\Http\Resources\Booking\BookableDoctorResource;
use App\Http\Resources\Booking\BookableServiceResource;
use App\Http\Resources\Intake\IntakeResource;
use App\Models\Appointment;
use App\Models\Intake;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class IntakeController extends Controller
{
    /**
     * The intake form (PROJECT.md F5). It shows the patient's own answers, and the form is open only while the
     * Policy says so (their own booking, until check-in). After that the page shows the answers read-only.
     * A patient who does not own the booking gets 403, and so do staff and admins (they never read intake answers).
     */
    public function edit(Appointment $appointment): Response
    {
        Gate::authorize('view', $appointment);

        $appointment->load(['service', 'doctor.user', 'intake']);
        $intake = $appointment->intake;

        if ($intake !== null) {
            // Give the Policy the booking we already have, so it does not load its own copy.
            $intake->setRelation('appointment', $appointment);

            // The answers are health text. This is the check that keeps them from anyone but the owner.
            Gate::authorize('view', $intake);
        }

        return Inertia::render('my/appointments/intake', [
            'appointment' => [
                'ulid' => $appointment->ulid,
                'startsAt' => $appointment->starts_at->toIso8601ZuluString(),
                'endsAt' => $appointment->ends_at->toIso8601ZuluString(),
                'service' => (new BookableServiceResource($appointment->service))->resolve(),
                'doctor' => (new BookableDoctorResource($appointment->doctor))->resolve(),
            ],
            'intake' => $intake === null ? null : (new IntakeResource($intake))->resolve(),
            'durations' => array_map(
                fn (IntakeDuration $duration): array => ['value' => $duration->value, 'label' => $duration->label()],
                IntakeDuration::cases(),
            ),
            // The page only shows or hides the form from this flag. SaveIntake checks the Policy again on submit.
            'can' => [
                'save' => $intake === null
                    ? Gate::allows('create', [Intake::class, $appointment])
                    : Gate::allows('update', $intake),
            ],
        ]);
    }

    /**
     * Save the form. The patient is always the signed-in user and the booking comes from the URL. The "until
     * check-in" rule, the row lock and the encryption are in the Action, which checks the Policy itself.
     */
    public function update(SaveIntakeRequest $request, Appointment $appointment, SaveIntake $save): RedirectResponse
    {
        Gate::authorize('view', $appointment);

        $save->handle($request->user(), $appointment, $request->answers());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your intake was saved.')]);

        return to_route('my.appointments.intake', $appointment);
    }
}
