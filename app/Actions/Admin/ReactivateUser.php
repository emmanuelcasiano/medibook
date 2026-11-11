<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

final class ReactivateUser
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Turn an account back on. Only `is_active` changes: the role, password, 2FA and a doctor's profile and services
     * were never removed, so nothing is recreated or reset. The caller has already authorized this
     * (UserPolicy::reactivate). `user.reactivated` is written to the audit log in the same transaction, but only when
     * the account was inactive. Running it again on an active account changes nothing, so it is not an event (same
     * rule as DeactivateUser).
     */
    public function handle(User $actor, User $user): void
    {
        DB::transaction(function () use ($actor, $user): void {
            $wasInactive = ! $user->is_active;

            $user->forceFill(['is_active' => true])->save();

            if ($wasInactive) {
                $this->audit->record($actor, 'user.reactivated', $user);
            }
        });
    }
}
