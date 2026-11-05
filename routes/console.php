<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// PROJECT.md F9. The 60-minute window is run four times, and `reminder_sent_at` makes only the first run send.
// `withoutOverlapping` keeps a slow run from meeting the next one; its lock expires after 10 minutes if a run dies.
Schedule::command('medibook:send-reminders')->everyFifteenMinutes()->withoutOverlapping(10);
