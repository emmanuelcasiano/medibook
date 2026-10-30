<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Intake;
use App\Models\User;

/**
 * Intake answers are health data. Staff and admins get no ability here on purpose (least privilege):
 * they only see "filled or not" and the emergency flag, and those come from the appointment.
 */
final class IntakePolicy
{
    /** Read the raw answers: the patient who owns them, or the appointment's doctor. */
    public function view(User $user, Intake $intake): bool
    {
        $appointment = $this->appointmentOf($intake);

        return ($user->isPatient() && $appointment->patient_id === $user->id)
            || ($user->isDoctor() && $appointment->doctor_id === $user->doctorId());
    }

    /** Start an intake for an appointment: its own patient, until check-in. */
    public function create(User $user, Appointment $appointment): bool
    {
        return $user->isPatient()
            && $appointment->patient_id === $user->id
            && $appointment->status === AppointmentStatus::Booked;
    }

    /** Edit an intake: its own patient, until check-in. */
    public function update(User $user, Intake $intake): bool
    {
        $appointment = $this->appointmentOf($intake);

        return $user->isPatient()
            && $appointment->patient_id === $user->id
            && $appointment->status === AppointmentStatus::Booked;
    }

    /** Read the AI summary: the doctor only. The patient reads their own answers, not the draft. */
    public function viewSummary(User $user, Intake $intake): bool
    {
        return $user->isDoctor() && $this->appointmentOf($intake)->doctor_id === $user->doctorId();
    }

    /** "Mark reviewed" on the AI summary. */
    public function reviewSummary(User $user, Intake $intake): bool
    {
        return $this->viewSummary($user, $intake);
    }

    private function appointmentOf(Intake $intake): Appointment
    {
        // loadMissing is an explicit load, so it is allowed even though lazy loading throws in strict mode.
        return $intake->loadMissing('appointment')->appointment;
    }
}
