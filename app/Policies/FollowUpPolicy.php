<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\FollowUpStatus;
use App\Models\FollowUp;
use App\Models\User;

/**
 * Who may do what with a follow-up (PROJECT.md section 9). Anything not listed here is denied.
 * Time rules (the window, the lead time) belong to the Actions, not here. Staff abilities arrive with step L.4.
 */
final class FollowUpPolicy
{
    /** Only a patient has a follow-up list. The controller scopes it to the signed-in patient. */
    public function viewAny(User $user): bool
    {
        return $user->isPatient();
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
