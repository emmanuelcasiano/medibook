<?php

declare(strict_types=1);

namespace App\Http\Controllers\Booking;

use App\Actions\Booking\BookAnyDoctor;
use App\Actions\Booking\BookAppointment;
use App\Actions\Booking\CancelAppointment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\ConfirmBookingRequest;
use App\Http\Resources\Booking\BookableDoctorResource;
use App\Http\Resources\Booking\BookableServiceResource;
use App\Models\Appointment;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class BookingController extends Controller
{
    /**
     * Step 4 of booking: the patient checks the visit and confirms. The route sits behind `auth` and `verified`, so
     * a guest who clicked Continue is sent to log in or register first and comes back to this same address (Laravel
     * keeps it as the "intended" URL). Nothing is held for them: the time is checked again when they confirm.
     */
    public function confirm(ConfirmBookingRequest $request, Service $service): Response
    {
        Gate::authorize('create', Appointment::class);

        $doctor = $request->doctor();
        $startsAt = $request->startsAt();

        return Inertia::render('booking/confirm', [
            'service' => (new BookableServiceResource($service))->resolve(),
            // null means "Any doctor": the doctor is picked when the booking is made.
            'doctor' => $doctor === null ? null : (new BookableDoctorResource($doctor))->resolve(),
            'startsAt' => $startsAt->toIso8601ZuluString(),
            'endsAt' => $startsAt->addMinutes($service->duration_minutes)->toIso8601ZuluString(),
            'cancelUntil' => $startsAt->subHours(CancelAppointment::PATIENT_CANCEL_HOURS)->toIso8601ZuluString(),
        ]);
    }

    /**
     * Book the visit for the signed-in patient. The patient is always the logged-in user, never a field from the form.
     * The time was checked against the slot finders in the Form Request. A clash or a patient limit comes back as a
     * validation error from the Action.
     */
    public function store(ConfirmBookingRequest $request, Service $service, BookAppointment $book, BookAnyDoctor $bookAnyDoctor): RedirectResponse
    {
        Gate::authorize('create', Appointment::class);

        $patient = $request->user();
        $doctor = $request->doctor();

        $appointment = $doctor === null
            ? $bookAnyDoctor->handle($patient, $service, $request->startsAt())
            : $book->handle($patient, $doctor, $service, $request->startsAt());

        return to_route('my.appointments.confirmation', $appointment);
    }

    /** The "You're booked" page. A patient sees their own booking only; for anyone else the Policy answers 403. */
    public function confirmation(Appointment $appointment): Response
    {
        Gate::authorize('view', $appointment);

        $appointment->load(['service', 'doctor.user']);

        return Inertia::render('booking/confirmation', [
            'appointment' => [
                'ulid' => $appointment->ulid,
                'startsAt' => $appointment->starts_at->toIso8601ZuluString(),
                'endsAt' => $appointment->ends_at->toIso8601ZuluString(),
                'cancelUntil' => $appointment->starts_at->subHours(CancelAppointment::PATIENT_CANCEL_HOURS)->toIso8601ZuluString(),
                'service' => (new BookableServiceResource($appointment->service))->resolve(),
                'doctor' => (new BookableDoctorResource($appointment->doctor))->resolve(),
            ],
        ]);
    }
}
