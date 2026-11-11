<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;

/**
 * Vital signs are health data. The nurse writes them, and only the treating doctor reads them. Deny by default:
 * patients, staff and admins get no ability here, and the nurse gets none to read what was saved.
 *
 * `create`, `update` and `view` take the appointment, not the record, so they can run before a record exists and never
 * need a lazy load. `create` and `update` are the same rule today and stay two abilities, so one can change without
 * the other.
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

    /**
     * Read the values: the appointment's own doctor only, in any status (the doctor may look back at a finished
     * visit). Not the nurse who took them, not another doctor, not the patient, staff or admin. An admin audits who
     * looked through the audit log, not by reading the values.
     */
    public function view(User $user, Appointment $appointment): bool
    {
        return $user->isDoctor() && $appointment->doctor_id === $user->doctorId();
    }
}
