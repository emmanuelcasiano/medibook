<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Only the admin reads the AI usage (PROJECT.md section 3). It is about the whole clinic, not one summary, so the
 * ability takes no model. Any other ability on a summary is undefined here and so denied for everyone: the summary
 * text itself is reached through the intake and appointment policies, never through this one.
 */
final class IntakeSummaryPolicy
{
    public function viewUsage(User $user): bool
    {
        return $user->isAdmin();
    }
}
