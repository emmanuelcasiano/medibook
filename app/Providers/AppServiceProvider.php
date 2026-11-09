<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\DemoMode;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Limiters that routes attach with `throttle:<name>`. Named here, so their counters stay apart from the
     * plain `throttle:20,1` routes.
     */
    protected function configureRateLimiting(): void
    {
        // The public booking pages run several queries per doctor. 60 views a minute per visitor is enough to click
        // through every day of the booking window, and too few to hammer the database.
        RateLimiter::for('booking-pages', fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->ip()));

        // Booking writes a row and takes two row locks, so it is capped per signed-in patient (not per IP, which
        // clinics and phones share). 10 a minute is far above a real patient and too few to hold the locks in a loop.
        RateLimiter::for('booking', fn (Request $request): Limit => Limit::perMinute(10)->by((string) (Auth::id() ?? $request->ip())));

        // The intake form locks the appointment row and writes an encrypted blob. A patient edits it a few times, so
        // 20 a minute per signed-in patient is far above real use and too few to hold the lock in a loop.
        RateLimiter::for('intake', fn (Request $request): Limit => Limit::perMinute(20)->by((string) (Auth::id() ?? $request->ip())));

        // Front-desk buttons (check in, no-show, and later cancel and move). A busy desk can do one a few seconds,
        // so the cap is higher than for patients: 60 a minute per staff member, and still far too few for a loop.
        RateLimiter::for('staff-actions', fn (Request $request): Limit => Limit::perMinute(60)->by((string) (Auth::id() ?? $request->ip())));

        // The walk-in dialog's search and time lists read rows only, but fire on typing (the page waits a moment after the
        // last key). 120 a minute per staff member is far above a person typing, and apart from `staff-actions` so a busy
        // search can't use up the allowance for check-in and cancel.
        RateLimiter::for('staff-lookups', fn (Request $request): Limit => Limit::perMinute(120)->by((string) (Auth::id() ?? $request->ip())));

        // Doctor buttons that write under a row lock ("Mark reviewed", and later visit notes and "Finish visit"). A
        // doctor works through a few patients an hour, so 30 a minute is far above real use and too few for a loop.
        RateLimiter::for('doctor-actions', fn (Request $request): Limit => Limit::perMinute(30)->by((string) (Auth::id() ?? $request->ip())));

        // The one-click demo login is open to every guest (no password), and each click starts a session. It is per IP
        // because the visitor has no account yet. 20 a minute covers a reviewer trying all five roles, and no more.
        RateLimiter::for('demo-login', fn (Request $request): Limit => Limit::perMinute(20)->by((string) $request->ip()));

        // Sign-up and password reset are open to every guest and write a row or send an email. Per IP, because the
        // visitor has no account yet. 5 a minute is more than a person needs (a typo or two) and too few to fill the
        // users table or flood an inbox. The reset form and the "send link" form share one counter, as a real reset
        // uses each once. Attached by `ThrottleAccountForms`, since Fortify only limits login and 2FA.
        RateLimiter::for('register', fn (Request $request): Limit => Limit::perMinute(5)->by((string) $request->ip()));
        RateLimiter::for('password-reset', fn (Request $request): Limit => Limit::perMinute(5)->by((string) $request->ip()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Behind nginx the app sees plain HTTP, so without this a link, a redirect or a Wayfinder URL could be built as
        // http:// on a TLS site. Only production: local development runs on http://localhost.
        URL::forceHttps(app()->isProduction());

        // Throws on lazy loading (N+1), silently dropped attributes and missing attributes.
        // Off in production so a mistake there shows a page, not an error.
        Model::shouldBeStrict(! app()->isProduction());

        // `migrate:fresh` is refused in production, except on the demo server: its nightly reset (routes/console.php)
        // is that command, and everything in that database is fake data.
        DB::prohibitDestructiveCommands(
            app()->isProduction() && ! DemoMode::enabled(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
