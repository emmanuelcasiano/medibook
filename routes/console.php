<?php

use App\Support\DemoMode;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// PROJECT.md F9. The 60-minute window is run four times, and `reminder_sent_at` makes only the first run send.
// `withoutOverlapping` keeps a slow run from meeting the next one; its lock expires after 10 minutes if a run dies.
Schedule::command('medibook:send-reminders')->everyFifteenMinutes()->withoutOverlapping(10);

// PROJECT.md F12. Wipes the whole database and seeds the fake clinic again, so visitors' changes don't pile up. It is
// always registered but `when()` checks APP_DEMO at run time, so on any other install it never runs. 03:00 in Manila is
// the quietest hour; the reset also signs everyone out (sessions live in the database) and drops queued jobs.
Schedule::command('migrate:fresh', ['--seed' => true, '--force' => true])
    ->dailyAt('03:00')
    ->timezone((string) config('app.clinic_timezone'))
    ->when(fn (): bool => DemoMode::enabled());
