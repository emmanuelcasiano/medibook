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

final class CheckInAppointment
{
    /**
     * Check a patient in at the front desk: `booked` becomes `checked_in`, and `checked_in_at` records when.
     *
     * Staff may check in at any time while the booking is `booked`, because a patient can arrive early. The time rule
     * in PROJECT.md section 4 is only for the no-show (see `MarkNoShow`). A checked-in booking still holds its slot
     * (`checked_in` is an active status), and the patient can no longer edit their intake (Day 8 reads the status).
     *
     * This Action checks the Policy itself (architecture.md section 3), so a caller that skips the controller, such
     * as a job or the assistant, can't skip the check either.
     *
     * @throws AuthorizationException When $actor may not check this appointment in (only staff may).
     * @throws ValidationException When the appointment is not `booked`.
     */
    public function handle(User $actor, Appointment $appointment): Appointment
    {
        // Before any lock or write, so a user who may not check in learns nothing about the booking's state.
        Gate::forUser($actor)->authorize('checkIn', $appointment);

        return DB::transaction(function () use ($appointment): Appointment {
            // Lock and re-read the row. The model in the caller's hand may be stale, and without the lock a check-in
            // and a cancel (or a no-show) running together could both pass the status check below.
            $current = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if ($current->status !== AppointmentStatus::Booked) {
                throw ValidationException::withMessages(['appointment' => 'Only a booked appointment can be checked in.']);
            }

            $current->update([
                'status' => AppointmentStatus::CheckedIn,
                'checked_in_at' => CarbonImmutable::now(),
            ]);

            return $current;
        });
    }
}
