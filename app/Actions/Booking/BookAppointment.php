<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BookAppointment
{
    /** The `starts_at` error when the doctor is busy then. BookAnyDoctor reads it to know it may try the next doctor. */
    public const string TIME_TAKEN_MESSAGE = 'This time is no longer free. Please pick another slot.';

    /** PROJECT.md F3: how many future active bookings one patient may hold. */
    private const int MAX_UPCOMING_PER_PATIENT = 3;

    /**
     * Book $service with $doctor for $patient at $startsAt. This is the last line of defence against a double
     * booking (PROJECT.md F4): the slot the user picked may have been taken since the slot list was built.
     *
     * Three layers, each enough on its own to stop two active bookings from sharing a slot:
     * 1. the row lock on the doctor, so bookings for one doctor run one at a time;
     * 2. the overlap check below, which counts the buffer of both visits;
     * 3. the unique index on (doctor_id, starts_at, active_key), which refuses the same start twice.
     *
     * The patient has limits too (PROJECT.md F3): at most 3 future active bookings, and no two of their own
     * bookings overlapping, even with different doctors.
     *
     * The caller has already authorized this and chosen a slot from FindAvailableSlots. This Action checks the
     * service and doctor pair, the patient limits and the overlap; it does not re-check working hours, time off
     * or the lead time.
     *
     * @throws ValidationException When the service is switched off, the doctor's account is switched off, the doctor
     *                             does not offer the service, the patient is over a limit, or the time is taken.
     */
    public function handle(User $patient, Doctor $doctor, Service $service, CarbonImmutable $startsAt): Appointment
    {
        // The database holds UTC, and the query builder writes a date as it is, with no timezone conversion.
        // Without this, a Manila time would be stored and compared as if it were UTC.
        $startsAt = $startsAt->utc();
        $endsAt = $startsAt->addMinutes($service->duration_minutes);
        // The buffer keeps the doctor free for the next patient, so it blocks the slot like the visit does.
        $blockedUntil = $endsAt->addMinutes($service->buffer_minutes);

        return DB::transaction(function () use ($patient, $doctor, $service, $startsAt, $endsAt, $blockedUntil): Appointment {
            // Lock the patient row first. The doctor lock below can't stop one patient from booking two different
            // doctors at the same moment, so without this both requests could pass the limit checks. Always patient
            // then doctor, in that order, so two transactions can never wait on each other.
            User::query()->whereKey($patient->id)->lockForUpdate()->first();

            // Lock the doctor row. A second booking for this doctor waits here until this transaction ends,
            // so two requests can't both pass the overlap check below.
            Doctor::query()->whereKey($doctor->id)->lockForUpdate()->first();

            // Read the service inside the lock: it may have been switched off since the page was built.
            if (! Service::query()->whereKey($service->id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['service_id' => 'This service is not available for booking.']);
            }

            // Read inside the lock too: a doctor whose account was switched off keeps their old bookings, but takes no new ones.
            if (! Doctor::query()->whereKey($doctor->id)->bookable()->exists()) {
                throw ValidationException::withMessages(['doctor_id' => 'This doctor is not taking bookings.']);
            }

            // A wrong doctor and service pair can't be booked, even if the caller skipped the slot finder.
            if (! $doctor->services()->whereKey($service->id)->exists()) {
                throw ValidationException::withMessages(['doctor_id' => 'This doctor does not offer that service.']);
            }

            // A booking that has already started no longer counts as upcoming.
            $upcoming = Appointment::query()
                ->where('patient_id', $patient->id)
                ->active()
                ->where('starts_at', '>', CarbonImmutable::now())
                ->count();

            if ($upcoming >= self::MAX_UPCOMING_PER_PATIENT) {
                throw ValidationException::withMessages([
                    'starts_at' => sprintf('You can have at most %d upcoming appointments. Cancel one before booking another.', self::MAX_UPCOMING_PER_PATIENT),
                ]);
            }

            // The patient can't be in two places at once. This compares the visits only: the buffer is the doctor's
            // clean-up time, so a patient may go from one visit straight to the next.
            $ownClash = Appointment::query()
                ->where('patient_id', $patient->id)
                ->active()
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->exists();

            if ($ownClash) {
                throw ValidationException::withMessages(['starts_at' => 'You already have an appointment at this time. Please pick another slot.']);
            }

            // Overlap test: an active booking clashes when it starts before the new one is free again
            // and is itself blocked until after the new one starts. Touching is not overlapping.
            $clash = Appointment::query()
                ->where('doctor_id', $doctor->id)
                ->active()
                ->where('starts_at', '<', $blockedUntil)
                ->where('blocked_until', '>', $startsAt)
                ->exists();

            if ($clash) {
                throw ValidationException::withMessages(['starts_at' => self::TIME_TAKEN_MESSAGE]);
            }

            return Appointment::create([
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'service_id' => $service->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'blocked_until' => $blockedUntil,
                'status' => AppointmentStatus::Booked,
            ]);
        });
    }
}
