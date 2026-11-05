<?php

declare(strict_types=1);

namespace App\Http\Controllers\Doctor;

use App\Actions\Doctor\CompleteAppointment;
use App\Actions\Doctor\MarkSummaryReviewed;
use App\Enums\SummaryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Doctor\ReviewSummaryRequest;
use App\Http\Resources\Doctor\AppointmentDetailResource;
use App\Http\Resources\Doctor\IntakeDetailResource;
use App\Http\Resources\Doctor\IntakeSummaryResource;
use App\Http\Resources\Doctor\VisitNoteResource;
use App\Models\Appointment;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
     * have their own rules (staff and admins never get them). The intake, summary and note props are built only after
     * their check has passed. The birth date is never sent: only the age in years.
     *
     * Every open writes a view row for each piece of health data the page really carries (PROJECT.md F10): the intake
     * answers, a ready AI draft, the doctor's note. The rows are written after the last check has passed, so a refused
     * request leaves none, and before the page is built, so no content goes out without its row. If the write fails,
     * the page fails with it (the content is not shown without a trace). The rows hold the appointment id and nothing
     * else, never the health text.
     */
    public function show(Request $request, Appointment $appointment, AuditLogger $audit): Response
    {
        Gate::authorize('view', $appointment);

        // Column lists keep the page to what it shows. `birth_date` is loaded so the age can be worked out; it is not
        // in any prop. A fixed number of queries, whatever the patient's history.
        $appointment->load([
            'patient:id,name,birth_date',
            'service:id,name',
            'intake',
            'intake.summary',
            'visitNotes',
        ]);

        $intake = $appointment->intake;
        $summary = $intake?->summary;

        // The doctor's private note. Only the appointment's own doctor gets here (the route is for doctors and the
        // appointment Policy refused every other one), and the note Policy is checked before its text is built.
        $note = $appointment->visitNotes->first();

        if ($note !== null) {
            Gate::authorize('view', $note);
        }

        if ($intake !== null) {
            // Give the Policies the appointment we already have, so they do not load their own copy.
            $intake->setRelation('appointment', $appointment);

            Gate::authorize('view', $intake);
            Gate::authorize('viewSummary', $intake);
        }

        $viewed = ['appointment_id' => $appointment->id];
        $doctor = $request->user();

        if ($intake !== null) {
            $audit->record($doctor, 'intake.viewed', $intake, $viewed);
        }

        // A pending, failed or skipped summary has no AI text on the page, so only a ready draft counts as a view.
        if ($summary !== null && $summary->status === SummaryStatus::Ready) {
            $audit->record($doctor, 'summary.viewed', $summary, $viewed);
        }

        if ($note !== null) {
            $audit->record($doctor, 'notes.viewed', $note, $viewed);
        }

        return Inertia::render('doctor/appointments/show', [
            'appointment' => (new AppointmentDetailResource($appointment))->resolve(),
            'intake' => $intake === null ? null : (new IntakeDetailResource($intake))->resolve(),
            'summary' => $summary === null ? null : (new IntakeSummaryResource($summary))->resolve(),
            'visitNote' => $note === null ? null : (new VisitNoteResource($note))->resolve(),
            // Only for showing the form. The Policy settled who; this is the state. SaveVisitNote checks it again on submit.
            'canSaveVisitNote' => $appointment->status->allowsVisitNote(),
        ]);
    }

    /**
     * "Mark reviewed" on the AI draft. The doctor and the time come from the server. The only input is the token of
     * the draft that was on the page. The appointment Policy runs here, and the Action runs the intake Policy, the
     * ready-summary rule and the token check.
     */
    public function review(ReviewSummaryRequest $request, Appointment $appointment, MarkSummaryReviewed $markReviewed): RedirectResponse
    {
        Gate::authorize('view', $appointment);

        $markReviewed->handle($request->user(), $appointment, $request->validated('version'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The summary is marked as reviewed.')]);

        return to_route('doctor.appointments.show', $appointment);
    }

    /**
     * "Finish visit". No input: the doctor comes from the session and the new status is fixed by the Action. The
     * appointment Policy runs here, and again inside the Action.
     */
    public function complete(Request $request, Appointment $appointment, CompleteAppointment $completeAppointment): RedirectResponse
    {
        Gate::authorize('complete', $appointment);

        $completeAppointment->handle($request->user(), $appointment);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The visit is finished.')]);

        return to_route('doctor.appointments.show', $appointment);
    }
}
