<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

final class UpdateUser
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Change a user's name and email. Nothing else: the role, `is_active` and the password are not fillable and are
     * never passed in. The caller has already authorized this (UserPolicy::update).
     *
     * A changed email is unverified until the person clicks the link sent to the new address, so a typo can't hand the
     * account to a stranger. `user.updated` is written in the same transaction with the names of the changed fields
     * only, never the old or new values (an email is personal data). Saving the same values again changes nothing, so
     * it is not an event (same rule as DeactivateUser).
     */
    public function handle(User $actor, User $user, string $name, string $email): User
    {
        $emailChanged = DB::transaction(function () use ($actor, $user, $name, $email): bool {
            $user->fill(['name' => $name, 'email' => $email]);

            $fields = array_keys($user->getDirty());
            $emailChanged = in_array('email', $fields, true);

            if ($emailChanged) {
                $user->email_verified_at = null;
            }

            if ($fields === []) {
                return false;
            }

            $user->save();

            $this->audit->record($actor, 'user.updated', $user, ['fields' => $fields]);

            return $emailChanged;
        });

        // After the commit, so a rolled-back change never emails the new address.
        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return $user;
    }
}
