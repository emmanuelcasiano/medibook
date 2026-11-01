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

final class MarkNoShow
{
    /**
     * Record that a patient did not arrive: `booked` becomes `no_show`. Only once the start time has come (PROJECT.md
     * section 4), so staff can't write off a patient who is not yet late. Exactly at the start is allowed.
     *
     * `no_show` is not an active status, so `active_key` becomes NULL and the slot is free for another booking at
     * once.
     *
     * This Action checks the Policy itself (architecture.md section 3), so a caller that skips the controller can't
     * skip the check either.
     *
     * @throws AuthorizationException When $actor may not mark a no-show (only staff may).
     * @throws ValidationException When the appointment is not `booked`, or has not started yet.
     */
    public function handle(User $actor, Appointment $appointment): Appointment
    {
        // Before any lock or write, so a user who may not do this learns nothing about the booking's state.
        Gate::forUser($actor)->authorize('markNoShow', $appointment);

        return DB::transaction(function () use ($appointment): Appointment {
            // Lock and re-read the row, for the same reason as in CheckInAppointment: a stale model must not decide.
            $current = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if ($current->status !== AppointmentStatus::Booked) {
                throw ValidationException::withMessages(['appointment' => 'Only a booked appointment can be marked as a no-show.']);
            }

            // Read the start from the locked row, not from the caller's copy. `starts_at` is UTC, and so is now().
            if ($current->starts_at > CarbonImmutable::now()) {
                throw ValidationException::withMessages(['appointment' => 'A no-show can only be marked once the appointment has started.']);
            }

            $current->update(['status' => AppointmentStatus::NoShow]);

            return $current;
        });
    }
}
