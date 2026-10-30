<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DeactivateUser
{
    /**
     * Turn an account off. Users are never hard-deleted, because appointments and the audit log point at them.
     * The caller has already authorized this (UserPolicy::deactivate).
     */
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->forceFill([
                'is_active' => false,
                // A new remember token makes any "remember me" cookie on other devices useless.
                'remember_token' => null,
            ])->save();

            // Drop every stored session of this user, so they are signed out on all devices right now.
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }
        });
    }
}
