<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Throws on lazy loading (N+1), silently dropped attributes and missing attributes.
        // Off in production so a mistake there shows a page, not an error.
        Model::shouldBeStrict(! app()->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
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
