<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CancelAppointment
{
    /** PROJECT.md section 4: a patient can cancel up to this many hours before the start. */
    public const int PATIENT_CANCEL_HOURS = 2;

    /** `appointments.cancel_reason` is a plain VARCHAR(255). */
    private const int MAX_REASON_LENGTH = 255;

    /**
     * Cancel $appointment for $actor. A patient may cancel their own booking up to 2 hours before it starts, and
     * a reason is optional. Staff may cancel any booking at any time, and must give a reason.
     *
     * Moving the status to `cancelled` makes `active_key` NULL, so the slot is free for another booking at once.
     *
     * This Action checks the Policy itself (architecture.md section 3): PROJECT.md plans to let the AI assistant call
     * it as the patient, so the check can't be left to a controller.
     *
     * @throws AuthorizationException When $actor may not cancel this appointment.
     * @throws ValidationException When the appointment is not `booked`, a patient is inside the 2 hour limit, or
     *                             staff gave no reason or one that is too long.
     */
    public function handle(User $actor, Appointment $appointment, ?string $reason = null): Appointment
    {
        // Before any lock or write, so a user who may not cancel this booking learns nothing about its state.
        Gate::forUser($actor)->authorize('cancel', $appointment);

        $reason = $reason === null ? null : trim($reason);
        $reason = $reason === '' ? null : $reason;

        if ($actor->isStaff() && $reason === null) {
            throw ValidationException::withMessages(['reason' => 'Please give a reason for cancelling this appointment.']);
        }

        if ($reason !== null && mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw ValidationException::withMessages(['reason' => sprintf('The reason may not be longer than %d characters.', self::MAX_REASON_LENGTH)]);
        }

        return DB::transaction(function () use ($actor, $appointment, $reason): Appointment {
            // Lock and re-read the row. The model in the caller's hand may be stale, and without the lock a cancel
            // and a check-in (or two cancels) running together could both pass the status check below.
            $current = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            // Only a booked appointment can be cancelled. The Policy does not look at the status.
            if ($current->status !== AppointmentStatus::Booked) {
                throw ValidationException::withMessages(['appointment' => 'This appointment can no longer be cancelled.']);
            }

            // Exactly 2 hours before is still allowed. The time rule is for patients only: staff can cancel late.
            if (! $actor->isStaff() && $current->starts_at < CarbonImmutable::now()->addHours(self::PATIENT_CANCEL_HOURS)) {
                throw ValidationException::withMessages([
                    'appointment' => sprintf('Appointments can only be cancelled up to %d hours before they start. Please call the clinic.', self::PATIENT_CANCEL_HOURS),
                ]);
            }

            $current->update([
                'status' => AppointmentStatus::Cancelled,
                'cancelled_at' => CarbonImmutable::now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
            ]);

            return $current;
        });
    }
}
