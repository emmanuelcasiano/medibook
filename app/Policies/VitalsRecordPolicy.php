<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;

/**
 * Vital signs are health data, and only the nurse writes them. Deny by default: patients, staff, doctors and admins get
 * no ability here. Reading the values is a later part (the doctor's view), so there is no `view` yet.
 *
 * `create` and `update` take the appointment, not the record, so they can run before a record exists and never need a
 * lazy load. They are the same rule today and stay two abilities, so one can change without the other.
 */
final class VitalsRecordPolicy
{
    /** The triage queue: the nurse only. It lists patients and who still needs vitals, never a reading. */
    public function viewQueue(User $user): bool
    {
        return $user->isNurse();
    }

    /**
     * Take the first readings. Only while the patient is checked in: before that the patient is not at the clinic yet,
     * and after the doctor finishes (or the visit is cancelled) the readings are closed.
     */
    public function create(User $user, Appointment $appointment): bool
    {
        return $user->isNurse() && $appointment->status === AppointmentStatus::CheckedIn;
    }

    /** Correct a typo, until the visit is finished. */
    public function update(User $user, Appointment $appointment): bool
    {
        return $user->isNurse() && $appointment->status === AppointmentStatus::CheckedIn;
    }
}
