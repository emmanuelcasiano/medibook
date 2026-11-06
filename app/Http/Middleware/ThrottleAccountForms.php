<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limits the Fortify account forms that Fortify itself does not limit: register, "send me a reset link" and
 * "set the new password". Fortify only ships limiters for login and the 2FA challenge, and its routes are not ours to
 * edit. Without a limit, a script could fill the users table or flood one person's inbox with reset emails.
 *
 * This is a middleware on the `web` group and not a `throttle:` added to the routes at boot, because `route:cache`
 * (run by `php artisan optimize` on deploy) rebuilds a route on every lookup, so a middleware added to it at boot is
 * lost. The limiters are named in `AppServiceProvider`.
 */
final class ThrottleAccountForms
{
    /** Route name => limiter name. */
    private const LIMITERS = [
        'register.store' => 'register',
        'password.email' => 'password-reset',
        'password.update' => 'password-reset',
    ];

    public function __construct(private readonly ThrottleRequests $throttle) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $name = $request->route()?->getName();
        $limiter = $name === null ? null : (self::LIMITERS[$name] ?? null);

        if ($limiter === null) {
            return $next($request);
        }

        // Exactly three arguments: that is how ThrottleRequests knows the third one is a named limiter.
        return $this->throttle->handle($request, $next, $limiter);
    }
}
