<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;
use App\Models\VisitNote;

/** Visit notes are private to the doctor who wrote them. Admins and staff have no access. */
final class VisitNotePolicy
{
    public function view(User $user, VisitNote $note): bool
    {
        return $user->isDoctor() && $note->doctor_id === $user->doctorId();
    }

    /** Write a note for one of the doctor's own appointments. */
    public function create(User $user, Appointment $appointment): bool
    {
        return $user->isDoctor() && $appointment->doctor_id === $user->doctorId();
    }

    public function update(User $user, VisitNote $note): bool
    {
        return $this->view($user, $note);
    }
}
