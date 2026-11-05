<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CompleteAppointment
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The doctor finishes a visit: `checked_in` becomes `completed` (PROJECT.md F8). The status is the only thing
     * that changes. A completed visit still holds its slot (`completed` is an active status), and the doctor can keep
     * editing the visit note afterwards (`AppointmentStatus::allowsVisitNote()` covers `completed`).
     *
     * Only a visit that took place can be finished, so the patient must be checked in. There is no time rule: the
     * doctor decides when the visit is over.
     *
     * This Action checks the Policy itself (architecture.md section 3), so a caller that skips the controller can't
     * skip the check either. It runs before any lock or write, so a user who may not finish this visit learns nothing
     * about its state.
     *
     * @throws AuthorizationException When $actor may not finish this appointment (only its own doctor may).
     * @throws ValidationException When the appointment is not `checked_in`.
     */
    public function handle(User $actor, Appointment $appointment): Appointment
    {
        Gate::forUser($actor)->authorize('complete', $appointment);

        return DB::transaction(function () use ($actor, $appointment): Appointment {
            // Lock and re-read the row. The model in the caller's hand may be stale, and without the lock a finish and
            // a staff cancel or no-show running together could both pass the status check below.
            $current = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if ($current->status !== AppointmentStatus::CheckedIn) {
                throw ValidationException::withMessages(['appointment' => 'Only a checked-in visit can be finished.']);
            }

            $current->update(['status' => AppointmentStatus::Completed]);

            $this->audit->record($actor, 'appointment.completed', $current, [
                'status_from' => AppointmentStatus::CheckedIn->value,
                'status_to' => AppointmentStatus::Completed->value,
            ]);

            return $current;
        });
    }
}
