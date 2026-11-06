<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Actions\Booking\CancelAppointment;
use App\Actions\Booking\CheckInAppointment;
use App\Actions\Booking\MarkNoShow;
use App\Actions\Booking\MoveAppointment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\CancelAppointmentRequest;
use App\Http\Requests\Staff\MoveAppointmentRequest;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The buttons in the schedule's side panel. Staff only: the `staff` route group also lets an admin in to look at the
 * schedule, so it is the Policy here (and again in each Action) that turns an admin away with a 403.
 *
 * Check-in and no-show take no input, so they have no Form Request. Cancel takes a reason and move takes a doctor and
 * a new time, so those have one. The booking comes from the URL (by ULID) and the Action reads its state from the
 * database, never from the browser. The rules (status, "only after the start", whether the new time is free) are in
 * the Actions. A refusal there comes back as a validation error on `appointment` or `starts_at`.
 */
final class AppointmentController extends Controller
{
    public function checkIn(#[CurrentUser] User $user, Appointment $appointment, CheckInAppointment $checkIn): RedirectResponse
    {
        Gate::authorize('checkIn', $appointment);

        $appointment = $checkIn->handle($user, $appointment)->loadMissing('patient:id,name');

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name is checked in.', ['name' => $appointment->patient->name])]);

        return $this->backToPanel($appointment);
    }

    public function noShow(#[CurrentUser] User $user, Appointment $appointment, MarkNoShow $markNoShow): RedirectResponse
    {
        Gate::authorize('markNoShow', $appointment);

        $appointment = $markNoShow->handle($user, $appointment)->loadMissing('patient:id,name');

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was marked as a no-show.', ['name' => $appointment->patient->name])]);

        return $this->backToPanel($appointment);
    }

    public function cancel(CancelAppointmentRequest $request, #[CurrentUser] User $user, Appointment $appointment, CancelAppointment $cancel): RedirectResponse
    {
        Gate::authorize('cancel', $appointment);

        $appointment = $cancel->handle($user, $appointment, $request->validated('reason'))->loadMissing('patient:id,name');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The appointment of :name was cancelled.', ['name' => $appointment->patient->name])]);

        // A cancelled booking is no longer on the grid, so there is no panel to keep open. Back to the same day.
        return to_route('staff.home', [
            'date' => $appointment->starts_at->setTimezone((string) config('app.clinic_timezone'))->format('Y-m-d'),
        ]);
    }

    public function move(MoveAppointmentRequest $request, #[CurrentUser] User $user, Appointment $appointment, MoveAppointment $move): RedirectResponse
    {
        Gate::authorize('move', $appointment);

        $moved = $move->handle($user, $appointment, $request->doctor(), $request->startsAt())->loadMissing('patient:id,name');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The appointment of :name was moved to :time.', [
            'name' => $moved->patient->name,
            'time' => $moved->starts_at->setTimezone((string) config('app.clinic_timezone'))->format('j M Y, g:i A'),
        ])]);

        // The booking is a new row on the day of its new time, so open the panel of the new one.
        return $this->backToPanel($moved);
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
