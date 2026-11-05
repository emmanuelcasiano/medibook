<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use App\Notifications\AppointmentReminder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

// The reminder command (PROJECT.md F9, step 12.2). Nobody logs in to run it, so there is no Policy and no 403: the
// "denied" cases are the bookings it must leave alone. "Now" is a round minute, because `startingIn()` cuts seconds.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));
});

it('queues one reminder for a booking 24 hours away and marks it', function () {
    Notification::fake();
    $appointment = Appointment::factory()->booked()->startingIn(hours: 24)->create();

    $this->artisan('medibook:send-reminders')
        ->expectsOutput('Queued 1 reminder(s).')
        ->assertSuccessful();

    Notification::assertSentToTimes($appointment->patient, AppointmentReminder::class, 1);
    Notification::assertCount(1);
    expect($appointment->fresh()->reminder_sent_at)->toEqual(now());
});

it('sends nothing the second time, even a few runs later', function () {
    Notification::fake();
    Appointment::factory()->booked()->startingIn(hours: 24)->create();

    $this->artisan('medibook:send-reminders')->assertSuccessful();
    $this->travel(15)->minutes();
    $this->artisan('medibook:send-reminders')->expectsOutput('Queued 0 reminder(s).')->assertSuccessful();
    $this->travel(15)->minutes();
    $this->artisan('medibook:send-reminders')->assertSuccessful();

    Notification::assertCount(1);
});

it('sends to each patient only the reminder for their own booking', function () {
    Notification::fake();
    $first = Appointment::factory()->booked()->startingIn(hours: 24)->create();
    $second = Appointment::factory()->booked()->startingIn(hours: 24, minutes: 10)->create();
    $bystander = User::factory()->patient()->create();

    $this->artisan('medibook:send-reminders')->assertSuccessful();

    Notification::assertSentTo($first->patient, AppointmentReminder::class, fn (AppointmentReminder $n) => $n->appointment->is($first));
    Notification::assertSentTo($second->patient, AppointmentReminder::class, fn (AppointmentReminder $n) => $n->appointment->is($second));
    Notification::assertNotSentTo($bystander, AppointmentReminder::class);
});

it('leaves bookings outside the window alone', function (int $hours, int $minutes) {
    Notification::fake();
    $appointment = Appointment::factory()->booked()->startingIn(hours: $hours, minutes: $minutes)->create();

    $this->artisan('medibook:send-reminders')->expectsOutput('Queued 0 reminder(s).')->assertSuccessful();

    Notification::assertNothingSent();
    expect($appointment->fresh()->reminder_sent_at)->toBeNull();
})->with([
    'one minute before the window opens' => [23, 29],
    'one minute after the window closes' => [24, 31],
    'starting in 2 hours' => [2, 0],
    'starting in 3 days' => [72, 0],
    'already started' => [-1, 0],
]);

it('includes the two edges of the window', function (int $hours, int $minutes) {
    Notification::fake();
    Appointment::factory()->booked()->startingIn(hours: $hours, minutes: $minutes)->create();

    $this->artisan('medibook:send-reminders')->expectsOutput('Queued 1 reminder(s).')->assertSuccessful();

    Notification::assertCount(1);
})->with([
    'exactly 23.5 hours' => [23, 30],
    'exactly 24.5 hours' => [24, 30],
]);

it('leaves bookings that are not booked, or already reminded, alone', function (string $state) {
    Notification::fake();
    $appointment = Appointment::factory()->{$state}()->startingIn(hours: 24)->create();

    $this->artisan('medibook:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();
    expect($appointment->fresh()->reminder_sent_at)->toBeNull();
})->with(['cancelled', 'noShow', 'checkedIn', 'completed']);

it('does not send again to a booking that was already reminded', function () {
    Notification::fake();
    $sentAt = now()->subMinutes(30)->startOfMinute();
    $appointment = Appointment::factory()->booked()->startingIn(hours: 24)->create(['reminder_sent_at' => $sentAt]);

    $this->artisan('medibook:send-reminders')->assertSuccessful();

    Notification::assertNothingSent();
    expect($appointment->fresh()->reminder_sent_at)->toEqual($sentAt);
});

it('sends nothing when another run claims the booking between the select and the claim', function () {
    Notification::fake();
    $appointment = Appointment::factory()->booked()->startingIn(hours: 24)->create();
    $rival = now()->subSecond();

    // The rival run: right after this run has read its list, it marks the booking as reminded. This run still holds
    // the stale row, so only the conditional claim (`WHERE reminder_sent_at IS NULL`) can stop a second email.
    $raced = false;
    DB::listen(function (QueryExecuted $query) use (&$raced, $appointment, $rival): void {
        if (! $raced && str_starts_with($query->sql, 'select') && str_contains($query->sql, '`reminder_sent_at` is null')) {
            $raced = true;
            DB::table('appointments')->where('id', $appointment->id)->update(['reminder_sent_at' => $rival]);
        }
    });

    $this->artisan('medibook:send-reminders')->expectsOutput('Queued 0 reminder(s).')->assertSuccessful();

    expect($raced)->toBeTrue();
    Notification::assertNothingSent();
    expect($appointment->fresh()->reminder_sent_at)->toEqual($rival);
});

it('drops a queued reminder if the booking was cancelled before the worker ran', function () {
    $appointment = Appointment::factory()->booked()->startingIn(hours: 24)->create();
    $notification = new AppointmentReminder($appointment);

    expect($notification->shouldSend($appointment->patient, 'mail'))->toBeTrue();

    $appointment->update(['status' => AppointmentStatus::Cancelled]);

    expect($notification->shouldSend($appointment->patient, 'mail'))->toBeFalse();
});

it('is scheduled every 15 minutes without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'medibook:send-reminders'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/15 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
