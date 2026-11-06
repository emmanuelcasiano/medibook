<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\DemoMode;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff, doctors and admins can reach clinic screens only after 2FA is confirmed (PROJECT.md section 3).
 * It is attached to the app route groups, not to the settings routes, so they can still reach the setup page.
 *
 * The four seeded demo users skip it, in demo mode only: a visitor can't set up 2FA for an account that every
 * visitor shares, and `DemoMode::isDemoUser` is false for every other user and whenever APP_DEMO is off.
 */
final class EnsureTwoFactorIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->needsTwoFactorSetup() && ! DemoMode::isDemoUser($user)) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('Turn on two-factor authentication to use this part of MediBook.')]);

            return to_route('security.edit');
        }

        return $next($request);
    }
}
