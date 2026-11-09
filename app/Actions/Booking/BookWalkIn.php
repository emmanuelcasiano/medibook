<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Actions\Staff\CreatePatientAccount;
use App\Actions\Staff\NewPatient;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use App\Support\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class BookWalkIn
{
    public function __construct(
        private readonly CheckSlotIsOffered $isOffered,
        private readonly BookAppointment $book,
        private readonly BookAnyDoctor $bookAnyDoctor,
        private readonly CheckInAppointment $checkInAppointment,
        private readonly CreatePatientAccount $createPatient,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Book a patient who is at the front desk without a booking, and optionally check them in (PROJECT.md F3, F7).
     * `$patient` is an existing patient account, or the facts for a new one. `$doctor` null means "Any doctor".
     *
     * The booking is BookAppointment (or BookAnyDoctor, which calls it), so the three guards against a double booking,
     * the buffer, the patient limits (3 upcoming, no overlap) and the confirmation email all apply as they do for any
     * booking. `appointment.booked` names $actor, the staff member, and not the patient.
     *
     * This is the one place that skips the 2 hour lead time. The skip is limited here: the time must be a start time the
     * slot finders offer *from now* (working hours, time off, buffers and overlaps all still apply) and it must fall on
     * today, so this Action can't be used to book the future without the wait. The Form Request asks the same questions
     * to show a field error, but a caller without one (a job, an AI tool) can't skip them.
     *
     * One transaction: a new patient account, the booking and the check-in all stand or all roll back. A refused
     * booking (a taken time, a patient limit) leaves no account behind and sends no email.
     *
     * Besides the `appointment.booked` row (and `appointment.checked_in` when $checkIn is true), it writes one
     * `appointment.walk_in` row naming $actor: ids and two yes/no facts only.
     *
     * @throws AuthorizationException When $actor may not book a walk-in (only staff may).
     * @throws ValidationException When the patient is not an active patient account, the time is not offered or is not
     *                             today, or BookAppointment refuses (limits, a taken time, a switched-off service or doctor).
     */
    public function handle(User $actor, User|NewPatient $patient, Service $service, ?Doctor $doctor, CarbonImmutable $startsAt, bool $checkIn): Appointment
    {
        // Before any read or write, so a user who may not do this learns nothing.
        Gate::forUser($actor)->authorize('bookWalkIn', Appointment::class);

        $timezone = (string) config('app.clinic_timezone');

        if (! $startsAt->setTimezone($timezone)->isSameDay(CarbonImmutable::now($timezone))) {
            throw ValidationException::withMessages(['starts_at' => 'A walk-in can only be booked for today.']);
        }

        if (! $this->isOffered->handle($service, $doctor, $startsAt, ignoreLeadTime: true)) {
            throw ValidationException::withMessages(['starts_at' => BookAppointment::TIME_TAKEN_MESSAGE]);
        }

        if ($patient instanceof User && ! ($patient->isPatient() && $patient->is_active)) {
            // The same answer for a staff account, a switched-off patient and an id that is not a patient at all.
            throw ValidationException::withMessages(['patient_id' => 'Pick a patient from the list.']);
        }

        return DB::transaction(function () use ($actor, $patient, $service, $doctor, $startsAt, $checkIn): Appointment {
            $created = $patient instanceof NewPatient;
            $account = $patient instanceof NewPatient ? $this->createPatient->handle($actor, $patient) : $patient;

            $appointment = $doctor === null
                ? $this->bookAnyDoctor->handle($account, $service, $startsAt, $actor, ignoreLeadTime: true)
                : $this->book->handle($account, $doctor, $service, $startsAt, actor: $actor);

            if ($checkIn) {
                $appointment = $this->checkInAppointment->handle($actor, $appointment);
            }

            $this->audit->record($actor, 'appointment.walk_in', $appointment, [
                'patient_id' => $account->id,
                'doctor_id' => $appointment->doctor_id,
                'service_id' => $service->id,
                'patient_created' => $created,
                'checked_in' => $checkIn,
            ]);

            return $appointment;
        });
    }
}
