<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Staff, doctors and admins can reach clinic screens only after 2FA is confirmed (PROJECT.md section 3).
 * It is attached to the app route groups, not to the settings routes, so they can still reach the setup page.
 */
final class EnsureTwoFactorIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->needsTwoFactorSetup()) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('Turn on two-factor authentication to use this part of MediBook.')]);

            return to_route('security.edit');
        }

        return $next($request);
    }
}
