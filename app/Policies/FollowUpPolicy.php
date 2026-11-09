<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\FollowUpStatus;
use App\Models\FollowUp;
use App\Models\User;

/**
 * Who may do what with a follow-up (PROJECT.md section 9). Anything not listed here is denied.
 * Time rules (the window, the lead time) belong to the Actions, not here.
 */
final class FollowUpPolicy
{
    /** Only a patient has a follow-up list. The controller scopes it to the signed-in patient. */
    public function viewAny(User $user): bool
    {
        return $user->isPatient();
    }

    /**
     * The staff Follow-ups list. Staff run it; an admin may look (like the schedule, PROJECT.md section 3) but gets none
     * of its buttons, because `manage` below is staff only. The list holds names, dates and states, never the note.
     */
    public function viewStaffList(User $user): bool
    {
        return $user->isStaff() || $user->isAdmin();
    }

    /**
     * Book for the patient, log a call or send a reminder: staff only. The status is not checked here: each Action reads
     * it again under a row lock, where a second tab or a double click can't slip past.
     */
    public function manage(User $user, FollowUp $followUp): bool
    {
        return $user->isStaff();
    }

    /**
     * Start or finish a booking from this follow-up: the patient it was written for, and only while it is `open`.
     * A booked or dismissed follow-up has nothing left to book. The Action reads the status again under a lock.
     */
    public function book(User $user, FollowUp $followUp): bool
    {
        return $user->isPatient()
            && $followUp->patient_id === $user->id
            && $followUp->status === FollowUpStatus::Open;
    }
}
