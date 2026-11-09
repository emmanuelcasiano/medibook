<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Actions\Booking\BookWalkIn;
use App\Actions\Schedule\FindWalkInOptions;
use App\Actions\Staff\FindPatientsForWalkIn;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\ShowWalkInOptionsRequest;
use App\Http\Requests\Staff\ShowWalkInPatientsRequest;
use App\Http\Requests\Staff\StoreWalkInRequest;
use App\Http\Resources\Schedule\WalkInOptionsResource;
use App\Http\Resources\Schedule\WalkInPatientResource;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The "New walk-in" dialog on the staff schedule: a patient search, the choices it offers, and the booking. Staff only:
 * the `staff` route group also lets an admin in to look at the schedule, so it is the Policy here (and again in each
 * Action) that turns an admin away with a 403.
 *
 * The two lookups answer JSON for the dialog (no page change), and carry no health data: patients by name and email,
 * and visit types, doctors and times. The booking takes the dialog's choices and sends the staff member back to today's
 * schedule. The rules (the lead-time skip, today only, limits, a taken time) are in BookWalkIn.
 */
final class WalkInController extends Controller
{
    /** Patients matching a typed name or email. A POST, so the term stays out of addresses and logs. */
    public function patients(ShowWalkInPatientsRequest $request, FindPatientsForWalkIn $find): JsonResponse
    {
        Gate::authorize('bookWalkIn', Appointment::class);

        return response()->json([
            'patients' => WalkInPatientResource::collection($find->handle($request->term()))->resolve(),
        ]);
    }

    /** Visit types, and for a picked one its doctors and today's nearest free times. */
    public function options(ShowWalkInOptionsRequest $request, FindWalkInOptions $find): JsonResponse
    {
        Gate::authorize('bookWalkIn', Appointment::class);

        return response()->json(
            (new WalkInOptionsResource($find->handle($request->serviceId(), $request->doctorId(), $request->anyDoctor())))->resolve(),
        );
    }

    public function store(StoreWalkInRequest $request, #[CurrentUser] User $user, BookWalkIn $book): RedirectResponse
    {
        Gate::authorize('bookWalkIn', Appointment::class);

        $appointment = $book->handle(
            $user,
            $request->patient(),
            $request->service(),
            $request->doctor(),
            $request->startsAt(),
            $request->checkIn(),
        )->loadMissing(['patient:id,name', 'doctor.user:id,name']);

        $timezone = (string) config('app.clinic_timezone');

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Walk-in booked: :patient with :doctor at :time:checkedIn.', [
            'patient' => $appointment->patient->name,
            'doctor' => $appointment->doctor->user->name,
            'time' => $appointment->starts_at->setTimezone($timezone)->format('g:i A'),
            'checkedIn' => $request->checkIn() ? __(', checked in') : '',
        ])]);

        // A walk-in is always for today, so show today's schedule, where the new booking is.
        return to_route('staff.home', [
            'date' => $appointment->starts_at->setTimezone($timezone)->format('Y-m-d'),
        ]);
    }
}
