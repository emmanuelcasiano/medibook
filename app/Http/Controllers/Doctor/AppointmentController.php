<?php

declare(strict_types=1);

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Http\Resources\Doctor\AppointmentDetailResource;
use App\Http\Resources\Doctor\IntakeDetailResource;
use App\Http\Resources\Doctor\IntakeSummaryResource;
use App\Models\Appointment;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class AppointmentController extends Controller
{
    /**
     * One appointment of the doctor's own patient (PROJECT.md F8): the first name and age, the raw intake answers,
     * and the AI draft next to them. The page always gets the raw answers, so a pending, failed or skipped summary
     * never leaves the doctor without the patient's own words.
     *
     * Three checks, from the outside in. The appointment Policy refuses every other doctor, staff, admin and patient
     * (403). Then the intake Policy and the summary Policy, because the answers and the draft are health data and
     * have their own rules (staff and admins never get them). The intake and summary props are built only after their
     * check has passed. The birth date is never sent: only the age in years.
     */
    public function show(Appointment $appointment): Response
    {
        Gate::authorize('view', $appointment);

        // Column lists keep the page to what it shows. `birth_date` is loaded so the age can be worked out; it is not
        // in any prop. A fixed number of queries, whatever the patient's history.
        $appointment->load([
            'patient:id,name,birth_date',
            'service:id,name',
            'intake',
            'intake.summary',
        ]);

        $intake = $appointment->intake;
        $summary = $intake?->summary;

        if ($intake !== null) {
            // Give the Policies the appointment we already have, so they do not load their own copy.
            $intake->setRelation('appointment', $appointment);

            Gate::authorize('view', $intake);
            Gate::authorize('viewSummary', $intake);
        }

        return Inertia::render('doctor/appointments/show', [
            'appointment' => (new AppointmentDetailResource($appointment))->resolve(),
            'intake' => $intake === null ? null : (new IntakeDetailResource($intake))->resolve(),
            'summary' => $summary === null ? null : (new IntakeSummaryResource($summary))->resolve(),
        ]);
    }
}
