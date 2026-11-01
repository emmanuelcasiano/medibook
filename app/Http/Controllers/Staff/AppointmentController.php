<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Actions\Booking\CancelAppointment;
use App\Actions\Booking\CheckInAppointment;
use App\Actions\Booking\MarkNoShow;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\CancelAppointmentRequest;
use App\Models\Appointment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The buttons in the schedule's side panel. Staff only: the `staff` route group also lets an admin in to look at the
 * schedule, so it is the Policy here (and again in each Action) that turns an admin away with a 403.
 *
 * Check-in and no-show take no input, so they have no Form Request. Cancel takes a reason, which has one. The booking
 * comes from the URL (by ULID) and the Action reads its state from the database, never from the browser. The rules
 * (status, "only after the start") are in the Actions. A refusal there comes back as a validation error on
 * `appointment`.
 */
final class AppointmentController extends Controller
{
    public function checkIn(Request $request, Appointment $appointment, CheckInAppointment $checkIn): RedirectResponse
    {
        Gate::authorize('checkIn', $appointment);

        $appointment = $checkIn->handle($request->user(), $appointment)->loadMissing('patient:id,name');

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name is checked in.', ['name' => $appointment->patient->name])]);

        return $this->backToPanel($appointment);
    }

    public function noShow(Request $request, Appointment $appointment, MarkNoShow $markNoShow): RedirectResponse
    {
        Gate::authorize('markNoShow', $appointment);

        $appointment = $markNoShow->handle($request->user(), $appointment)->loadMissing('patient:id,name');

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was marked as a no-show.', ['name' => $appointment->patient->name])]);

        return $this->backToPanel($appointment);
    }

    public function cancel(CancelAppointmentRequest $request, Appointment $appointment, CancelAppointment $cancel): RedirectResponse
    {
        Gate::authorize('cancel', $appointment);

        $appointment = $cancel->handle($request->user(), $appointment, $request->validated('reason'))->loadMissing('patient:id,name');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The appointment of :name was cancelled.', ['name' => $appointment->patient->name])]);

        // A cancelled booking is no longer on the grid, so there is no panel to keep open. Back to the same day.
        return to_route('staff.home', [
            'date' => $appointment->starts_at->setTimezone((string) config('app.clinic_timezone'))->format('Y-m-d'),
        ]);
    }

    /** The same day with the same panel open, so staff see the new status at once. */
    private function backToPanel(Appointment $appointment): RedirectResponse
    {
        return to_route('staff.home', [
            'date' => $appointment->starts_at->setTimezone((string) config('app.clinic_timezone'))->format('Y-m-d'),
            'appointment' => $appointment->ulid,
        ]);
    }
}
