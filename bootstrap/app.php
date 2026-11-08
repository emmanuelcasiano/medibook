<?php

use App\Http\Middleware\EnsureDemoModeIsOn;
use App\Http\Middleware\EnsureTwoFactorIsEnabled;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ThrottleAccountForms;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->alias([
            'demo-mode' => EnsureDemoModeIsOn::class,
            'role' => EnsureUserHasRole::class,
            'two-factor' => EnsureTwoFactorIsEnabled::class,
        ]);

        // In production nginx on this same server ends TLS and passes the request on as plain HTTP. Trusting only
        // 127.0.0.1 lets the app believe `X-Forwarded-Proto: https` from nginx and from nobody else (a visitor who sends
        // that header is ignored). If the proxy ever moves to another host, change this address.
        $middleware->trustProxies(at: '127.0.0.1');

        // Global, not in the `web` group: `auth` and `throttle` are sorted ahead of group middleware, so a redirect to
        // the login page or a 429 would leave without these headers. A 404 for an unknown URL never enters `web` at all.
        $middleware->append(SecurityHeaders::class);

        // Runs on every web request, so a deactivated user is signed out on their next click, wherever they are.
        $middleware->web(append: [
            ThrottleAccountForms::class,
            EnsureUserIsActive::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A refused form is sent back with its input flashed to the session, and the sessions table is not encrypted.
        // The intake answers, the doctor's visit note (`body`) and the follow-up group (its `note` is health text) must
        // never be flashed. The whole `follow_up` group is left out, so a field added to it later is covered too. No
        // page needs it: Inertia forms keep the typed values on the screen. (The Handler already skips the password
        // fields.)
        $exceptions->dontFlash([
            'reason', 'symptoms', 'duration', 'allergies', 'medicines', 'fever', 'pregnant', 'recent_surgery',
            'body',
            'follow_up',
        ]);
    })->create();
