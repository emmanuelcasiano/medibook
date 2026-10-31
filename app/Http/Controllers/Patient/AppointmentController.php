<?php

declare(strict_types=1);

namespace App\Http\Controllers\Patient;

use App\Actions\Booking\BookAppointment;
use App\Actions\Booking\CancelAppointment;
use App\Actions\Booking\ListMyAppointments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\CancelAppointmentRequest;
use App\Http\Resources\Booking\MyAppointmentResource;
use App\Models\Appointment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class AppointmentController extends Controller
{
    /** "My appointments": the signed-in patient's own bookings, upcoming and past. */
    public function index(Request $request, ListMyAppointments $list): Response
    {
        Gate::authorize('viewAny', Appointment::class);

        $appointments = $list->handle($request->user());

        return Inertia::render('my/appointments/index', [
            'upcoming' => $appointments['upcoming']
                ->map(fn (Appointment $appointment): array => (new MyAppointmentResource($appointment))->resolve())
                ->values()
                ->all(),
            // The rules the page words for the patient, so the copy and the Actions can't drift apart. The Book
            // button is turned off at the limit; BookAppointment still refuses a 4th booking on its own.
            'cancelHours' => CancelAppointment::PATIENT_CANCEL_HOURS,
            'maxUpcoming' => BookAppointment::MAX_UPCOMING_PER_PATIENT,
            'canBook' => $appointments['upcoming']->count() < BookAppointment::MAX_UPCOMING_PER_PATIENT,
            'past' => $appointments['past']
                ->through(fn (Appointment $appointment): array => (new MyAppointmentResource($appointment))->resolve()),
        ]);
    }

    /**
     * Cancel one of the patient's own bookings. The 2 hour rule, the status check and the row lock are in the
     * Action. A refusal there comes back as a validation error on `appointment` or `reason`.
     */
    public function cancel(CancelAppointmentRequest $request, Appointment $appointment, CancelAppointment $cancel): RedirectResponse
    {
        Gate::authorize('cancel', $appointment);

        $cancel->handle($request->user(), $appointment, $request->validated('reason'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your appointment has been cancelled.')]);

        return to_route('my.home');
    }
}
