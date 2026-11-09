<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\FollowUp;
use App\Models\User;
use App\Notifications\AppointmentBooked;
use App\Support\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class MoveAppointment
{
    public function __construct(
        private readonly CancelAppointment $cancel,
        private readonly CheckSlotIsOffered $isOffered,
        private readonly BookAppointment $book,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Move $appointment to $doctor at $startsAt for $actor (PROJECT.md F7). The patient and the service stay the same;
     * only the doctor and the time change. The old row is cancelled and a new row is booked, in one transaction: if
     * the new time is refused for any reason, the old booking is still `booked` and nothing else has changed.
     *
     * Order inside the transaction: cancel first, then book.
     * - BookAppointment counts the patient's active bookings (limit 3) and their own overlaps. With the old booking
     *   still active, a patient at the limit could never be moved, and a 15 minute shift would clash with itself.
     * - The slot check runs after the cancel for the same reason: the old time must not block its own replacement.
     * - The new booking goes through BookAppointment, so the three guards (row lock, overlap check, unique index)
     *   apply to it exactly as they do to a patient's booking.
     *
     * Locks are always taken in this order: the old appointment row (CancelAppointment), then the patient, then the
     * doctor (BookAppointment). No other code takes them the other way round, so two moves can't wait on each other.
     *
     * This Action checks the Policy itself (architecture.md section 3), like CancelAppointment, so the check can't be
     * left to a controller.
     *
     * The audit log gets one `appointment.moved` row, on the new booking, with the old booking's id in the metadata.
     * The cancel and the booking inside it are told not to log, so the log reads as what the staff member did and
     * not as a cancel plus a booking. The row is written in the same transaction, so a refused move leaves none.
     *
     * The emails follow the same idea: the cancel and the booking send none, and the patient gets one
     * AppointmentBooked with the new date, time and doctor, queued after the commit.
     *
     * @throws AuthorizationException When $actor may not move this appointment.
     * @throws ValidationException When the appointment is not `booked`, the time is where the booking already is,
     *                             the time is not one the slot finder offers or is taken, or BookAppointment
     *                             refuses it (doctor off, wrong service, the patient's own overlap).
     */
    public function handle(User $actor, Appointment $appointment, Doctor $doctor, CarbonImmutable $startsAt): Appointment
    {
        // Before any lock or write, so a user who may not move this booking learns nothing about its state.
        Gate::forUser($actor)->authorize('move', $appointment);

        return DB::transaction(function () use ($actor, $appointment, $doctor, $startsAt): Appointment {
            // Locks and re-reads the row, and refuses anything that is not `booked`. The row it returns is the
            // current one, so everything below reads from it and not from the caller's possibly stale model.
            try {
                $old = $this->cancel->handle($actor, $appointment, $this->reason($startsAt), audit: false, notify: false, reopenFollowUp: false)
                    ->loadMissing(['patient', 'service']);
            } catch (ValidationException) {
                // For staff the only refusal left is the status (the reason is ours and the 2 hour rule is for
                // patients), and "can no longer be cancelled" would be the wrong thing to tell someone moving it.
                throw ValidationException::withMessages(['appointment' => 'This appointment can no longer be moved.']);
            }

            // Moving to where it already is would only swap the row for an identical one.
            if ($old->doctor_id === $doctor->id && $old->starts_at->getTimestamp() === $startsAt->getTimestamp()) {
                throw ValidationException::withMessages(['starts_at' => 'The appointment is already at this time. Please pick another slot.']);
            }

            // BookAppointment does not check working hours, time off or the lead time (the caller picks from the
            // slot finder), so ask the finder here. A time typed into the address bar is refused.
            if (! $this->isOffered->handle($old->service, $doctor, $startsAt)) {
                throw ValidationException::withMessages(['starts_at' => BookAppointment::TIME_TAKEN_MESSAGE]);
            }

            $new = $this->book->handle($old->patient, $doctor, $old->service, $startsAt, audit: false, notify: false);

            // A follow-up booked into the old row stays `booked`, now pointing at the new one. The cancel above was told
            // not to reopen it, and a refused move rolls all of this back.
            FollowUp::query()->where('booked_appointment_id', $old->id)->update(['booked_appointment_id' => $new->id]);

            $this->audit->record($actor, 'appointment.moved', $new, [
                'from_appointment_id' => $old->id,
                'from_doctor_id' => $old->doctor_id,
                'to_doctor_id' => $doctor->id,
                'from_starts_at' => $old->starts_at->toIso8601ZuluString(),
                'to_starts_at' => $new->starts_at->toIso8601ZuluString(),
            ]);

            $old->patient->notify(new AppointmentBooked($new));

            return $new;
        });
    }

    /**
     * The cancel reason for the old row. CancelAppointment needs one from staff. It names the new time, in clinic
     * time, and holds no health information.
     */
    private function reason(CarbonImmutable $startsAt): string
    {
        return sprintf('Moved to %s.', $startsAt->setTimezone((string) config('app.clinic_timezone'))->format('j M Y, g:i A'));
    }
}
