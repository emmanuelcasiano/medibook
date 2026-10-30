<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Doctor;
use App\Models\User;

/**
 * Managing doctor profiles is admin-only. There is no create or delete rule on purpose: a Doctor row is made
 * with the doctor's account (UserPolicy / CreateStaffUser), and accounts are deactivated, never deleted.
 * A missing method is denied by the Gate, so those actions stay closed.
 */
final class DoctorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Doctor $doctor): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Doctor $doctor): bool
    {
        return $user->isAdmin();
    }
}
