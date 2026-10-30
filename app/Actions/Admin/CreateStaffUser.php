<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Role;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateStaffUser
{
    /**
     * Create a staff, doctor or admin account. A doctor also gets their doctor profile.
     * The caller has already authorized this (UserPolicy::create).
     */
    public function handle(string $name, string $email, string $password, Role $role, ?string $specialty = null): User
    {
        // Patients register themselves with a birth date. This Action must never make one, or an admin
        // form could create a patient without the data the registration rules require.
        if ($role === Role::Patient) {
            throw new InvalidArgumentException('CreateStaffUser creates staff, doctor and admin accounts only.');
        }

        if ($role === Role::Doctor && ($specialty === null || $specialty === '')) {
            throw new InvalidArgumentException('A doctor needs a specialty.');
        }

        // One transaction, so a failed doctor profile can't leave a doctor user without a profile.
        return DB::transaction(function () use ($name, $email, $password, $role, $specialty): User {
            $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);

            // `role` and `is_active` are not fillable, so they are set here and only here.
            // The admin vouches for the email address, so the account starts verified.
            $user->forceFill([
                'role' => $role,
                'is_active' => true,
                'email_verified_at' => now(),
            ])->save();

            if ($role === Role::Doctor) {
                Doctor::create(['user_id' => $user->id, 'specialty' => $specialty]);
            }

            return $user;
        });
    }
}
