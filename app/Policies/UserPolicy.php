<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /** Create a staff, doctor, nurse or admin account. Patients register themselves. */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Create a patient account for someone at the front desk (the walk-in dialog). Staff only; `create` above stays
     * admin only and never makes a patient.
     */
    public function createPatient(User $user): bool
    {
        return $user->isStaff();
    }

    /**
     * Edit another user's name and email. Admin only, and not yourself (Profile settings is for your own account), so an
     * admin can't lock themselves out with a mistyped email that clears their verification.
     */
    public function update(User $user, User $target): bool
    {
        return $user->isAdmin() && $user->id !== $target->id;
    }

    /** An admin can't deactivate themselves, so the clinic can't lock itself out by accident. */
    public function deactivate(User $user, User $target): bool
    {
        return $user->isAdmin() && $user->id !== $target->id;
    }

    /** Admin only, and only for an account that is off. An active account has nothing to reactivate. */
    public function reactivate(User $user, User $target): bool
    {
        return $user->isAdmin() && ! $target->is_active;
    }
}
