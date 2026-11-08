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

    /**
     * The staff day schedule shows every doctor's bookings, so unlike `viewAny` it is not open to every role.
     * Staff run it; an admin may look (PROJECT.md section 3) but gets none of its action buttons, because those
     * Policy methods below are staff only. Patients and doctors have their own screens.
     */
    public function viewSchedule(User $user): bool
    {
        return $user->isStaff() || $user->isAdmin();
    }

    /**
     * Whether the schedule may say that an intake is still empty and that a booking has the emergency flag. Staff
     * only: an admin sees the schedule but not these (PROJECT.md section 3, least privilege). It is a fact about
     * health data, so it is never sent to a role that may not have it. No model argument: it is asked once for the
     * whole schedule.
     */
    public function viewIntakeStatus(User $user): bool
    {
        return $user->isStaff();
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

    /**
     * Ask the patient of a visit to come back: the appointment's own doctor only. The same people as `complete`, kept
     * as its own ability so a later change to one (for example, a follow-up after the visit is finished) can't
     * quietly change the other.
     */
    public function requestFollowUp(User $user, Appointment $appointment): bool
    {
        return $user->isDoctor() && $appointment->doctor_id === $user->doctorId();
    }
}
