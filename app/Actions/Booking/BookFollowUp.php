<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\FollowUpStatus;
use App\Models\Appointment;
use App\Models\FollowUp;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class BookFollowUp
{
    public function __construct(
        private readonly BookAppointment $book,
        private readonly BookAnyDoctor $bookAnyDoctor,
    ) {}

    /**
     * Book the visit a doctor asked for (PROJECT.md section 9). The patient, the service and the doctor come from the
     * follow-up, never from the caller, so a follow-up can't be used to book something else. `same_doctor` decides
     * between the asking doctor and "Any doctor". The booking itself is BookAppointment (or BookAnyDoctor, which calls
     * it), so the three guards against a double booking, the patient limits, the `appointment.booked` audit row and
     * the confirmation email all apply as they do for any booking.
     *
     * In one transaction, so a refused booking leaves the follow-up `open`:
     * - the follow-up row is locked and read again, because a second tab or a double click may have booked it already;
     * - it must still be `open`, and $startsAt must fall on a day of its window (clinic date);
     * - then the booking is made and the follow-up becomes `booked`, pointing at it.
     *
     * Locks are taken in one order: the follow-up row first, then the patient and the doctor (BookAppointment). The
     * cancel path (CancelAppointment) takes the appointment row and then the follow-up row, but only for a follow-up
     * that is already `booked`, which this Action refuses before it books, so the two can't wait on each other.
     *
     * Like BookAppointment, this trusts that $startsAt was chosen from the slot finders. The Form Request asks
     * CheckSlotIsOffered; a caller without a Form Request (a job, an AI tool) must do the same.
     *
     * Staff may call it for the patient (step L.4): the follow-up still decides who is booked and with whom, and the
     * patient gets the same confirmation email.
     *
     * The `follow_up.booked` audit event is step L.5, so only the `appointment.booked` row is written here.
     *
     * @throws AuthorizationException When $actor may not book this follow-up.
     * @throws ValidationException When the follow-up is no longer open, the day is outside its window, or
     *                             BookAppointment refuses (limits, a taken time, a doctor or service switched off).
     */
    public function handle(User $actor, FollowUp $followUp, CarbonImmutable $startsAt): Appointment
    {
        // Before any lock or read, so a user who may not book this follow-up learns nothing about its state. Staff book
        // for the patient (`manage`), a patient books their own (`book`). Any other role has neither, so it is refused.
        Gate::forUser($actor)->authorize($actor->isStaff() ? 'manage' : 'book', $followUp);

        // When staff press the button, the audit row names them and not the patient. A patient booking stays as it was.
        $bookedBy = $actor->isStaff() ? $actor : null;

        return DB::transaction(function () use ($followUp, $startsAt, $bookedBy): Appointment {
            $current = FollowUp::query()->whereKey($followUp->id)->lockForUpdate()->firstOrFail();

            if ($current->status !== FollowUpStatus::Open) {
                throw ValidationException::withMessages(['follow_up' => 'This follow-up can no longer be booked.']);
            }

            if (! $current->windowIncludes($startsAt)) {
                throw ValidationException::withMessages(['starts_at' => 'Pick a time between '.$current->windowLabel().'.']);
            }

            $current->loadMissing(['patient', 'doctor', 'service']);

            $appointment = $current->same_doctor
                ? $this->book->handle($current->patient, $current->doctor, $current->service, $startsAt, actor: $bookedBy)
                : $this->bookAnyDoctor->handle($current->patient, $current->service, $startsAt, $bookedBy);

            $current->update([
                'status' => FollowUpStatus::Booked,
                'booked_appointment_id' => $appointment->id,
            ]);

            return $appointment;
        });
    }
}
