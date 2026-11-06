<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the browser-hardening headers from security.md section 4 on every response. It is global middleware (see
 * `bootstrap/app.php`), so redirects to the login page, 429s and 404s carry them too.
 *
 * Left out on purpose: `Strict-Transport-Security` (it pins the browser to HTTPS, so add it only once HTTPS works on
 * the domain) and `Content-Security-Policy` (it needs care with Vite, so it is added and tested in production only).
 */
final class SecurityHeaders
{
    /**
     * Headers set on every response. `nosniff` stops a browser from guessing a type for a response, `DENY` blocks the
     * site from being framed (click-jacking on the staff and doctor screens), and the Referrer and Permissions
     * policies keep page URLs and device sensors away from other sites.
     */
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
    ];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
