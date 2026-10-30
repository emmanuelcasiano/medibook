<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

/**
 * Who may do what with an appointment (PROJECT.md section 3). Anything not listed here is denied.
 * Time rules (cancel up to 2 h before, no-show only after the start) belong to the Actions, not here.
 */
final class AppointmentPolicy
{
    /** Every role has an appointment list. The controller scopes each list to the user. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return match (true) {
            $user->isPatient() => $appointment->patient_id === $user->id,
            $user->isDoctor() => $appointment->doctor_id === $user->doctorId(),
            $user->isStaff(), $user->isAdmin() => true,
            default => false,
        };
    }

    public function create(User $user): bool
    {
        return $user->isPatient();
    }

    public function cancel(User $user, Appointment $appointment): bool
    {
        return ($user->isPatient() && $appointment->patient_id === $user->id) || $user->isStaff();
    }

    public function checkIn(User $user, Appointment $appointment): bool
    {
        return $user->isStaff();
    }

    public function markNoShow(User $user, Appointment $appointment): bool
    {
        return $user->isStaff();
    }

    public function move(User $user, Appointment $appointment): bool
    {
        return $user->isStaff();
    }

    /** Finish a visit: the appointment's own doctor only. */
    public function complete(User $user, Appointment $appointment): bool
    {
        return $user->isDoctor() && $appointment->doctor_id === $user->doctorId();
    }
}
