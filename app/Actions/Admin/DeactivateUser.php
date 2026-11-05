<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

final class DeactivateUser
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Turn an account off. Users are never hard-deleted, because appointments and the audit log point at them.
     * The caller has already authorized this (UserPolicy::deactivate). `user.deactivated` is written to the audit log
     * in the same transaction, but only when the account was active. Running it again on an inactive account changes
     * nothing, so it is not an event (same rule as MarkSummaryReviewed).
     */
    public function handle(User $actor, User $user): void
    {
        DB::transaction(function () use ($actor, $user): void {
            $wasActive = $user->is_active;

            $user->forceFill([
                'is_active' => false,
                // A new remember token makes any "remember me" cookie on other devices useless.
                'remember_token' => null,
            ])->save();

            // Drop every stored session of this user, so they are signed out on all devices right now.
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }

            if ($wasActive) {
                $this->audit->record($actor, 'user.deactivated', $user);
            }
        });
    }
}
