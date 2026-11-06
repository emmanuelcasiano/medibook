<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\DemoMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the demo routes when `APP_DEMO` is off. The login page hides the buttons, but hiding is only UX: this makes
 * the route itself answer 404, as if it did not exist, so a real install has no one-click login to find.
 */
final class EnsureDemoModeIsOn
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(DemoMode::enabled(), 404);

        return $next($request);
    }
}
