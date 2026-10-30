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

    /** Create a staff, doctor or admin account. Patients register themselves. */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /** An admin can't deactivate themselves, so the clinic can't lock itself out by accident. */
    public function deactivate(User $user, User $target): bool
    {
        return $user->isAdmin() && $user->id !== $target->id;
    }
}
