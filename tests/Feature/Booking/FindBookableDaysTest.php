<?php

declare(strict_types=1);

use App\Actions\Booking\FindBookableDays;
use App\Models\Service;
use Carbon\CarbonImmutable;

// "Now" is Friday 30 October 2026, 09:00 in Manila (01:00 UTC). Mondays after it: 2, 9, 16 and 23 November.
// The slot rules (time off, bookings, lead time) are tested in FindAvailableSlotsTest. These tests cover what this
// Action adds: the window of days, the grouping by clinic day, and the choice between one doctor and "Any doctor".
// The Action has no route of its own and does not read or change anything private, so there is no denied-access
// test here. The public pages that call it are tested in PublicBookingPagesTest.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));
});

it('returns every day from today to today plus the window, in date order, with empty days kept', function () {
    $service = Service::factory()->lasting(60)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '09:00']]);

    $days = app(FindBookableDays::class)->handle($service, $doctor);

    expect($days)->toHaveCount(31)
        ->and(array_key_first($days))->toBe('2026-10-30')
        ->and(array_key_last($days))->toBe('2026-11-29')
        ->and(array_keys($days))->toBe(collect(array_keys($days))->sort()->values()->all())
        ->and($days['2026-10-31'])->toBe([])
        ->and($days['2026-11-02'])->toHaveCount(1);
});

it('reads the window from config', function () {
    config(['medibook.booking_window_days' => 7]);
    $service = Service::factory()->lasting(60)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '09:00']]);

    $days = app(FindBookableDays::class)->handle($service, $doctor);

    expect($days)->toHaveCount(8)
        ->and(array_key_last($days))->toBe('2026-11-06')
        ->and($days['2026-11-02'])->toHaveCount(1)
        // 9 November is past the shorter window, so it is not a day at all.
        ->and($days)->not->toHaveKey('2026-11-09');
});

it('starts the window on the clinic date, even when it is still the day before in UTC', function () {
    // Sunday 1 November, 03:00 in Manila, is Saturday 31 October, 19:00 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-11-01 03:00', 'Asia/Manila'));
    $service = Service::factory()->lasting(60)->create();
    $doctor = doctorWorking($service, []);

    [$firstDay, $lastDay] = app(FindBookableDays::class)->window();

    expect($firstDay->format('Y-m-d H:i'))->toBe('2026-11-01 00:00')
        ->and($firstDay->timezoneName)->toBe('Asia/Manila')
        ->and($lastDay->format('Y-m-d'))->toBe('2026-12-01')
        ->and(array_key_first(app(FindBookableDays::class)->handle($service, $doctor)))->toBe('2026-11-01');
});

it('files a start time under its clinic day, not its UTC day', function () {
    $service = Service::factory()->lasting(60)->create();
    // 06:00 in Manila on Monday 2 November is 22:00 UTC on Sunday 1 November.
    $doctor = doctorWorking($service, [[1, '06:00', '08:00']]);

    $days = app(FindBookableDays::class)->handle($service, $doctor);

    expect($days['2026-11-01'])->toBe([])
        ->and($days['2026-11-02'])->toHaveCount(5)
        ->and($days['2026-11-02'][0]->utc()->format('Y-m-d H:i'))->toBe('2026-11-01 22:00');
});

it('keeps the start times of a day earliest first, across a lunch break', function () {
    $service = Service::factory()->lasting(60)->create();
    // The afternoon range is created first, so the order can't come from the order of creation.
    $doctor = doctorWorking($service, [[1, '13:00', '14:00'], [1, '08:00', '09:00']]);

    $days = app(FindBookableDays::class)->handle($service, $doctor);

    expect(slotTimes(collect($days['2026-11-02'])))->toBe(['08:00', '13:00']);
});

it('includes the last day of the window', function () {
    $service = Service::factory()->lasting(60)->create();
    // 29 November 2026 is a Sunday (weekday 0), exactly 30 days after today.
    $doctor = doctorWorking($service, [[0, '08:00', '09:00']]);

    $days = app(FindBookableDays::class)->handle($service, $doctor);

    expect($days['2026-11-29'])->toHaveCount(1);
});

it('asks every doctor of the service when no doctor is given, and lists a time once', function () {
    $service = Service::factory()->lasting(60)->create();
    $ana = doctorWorking($service, [[1, '08:00', '09:00']]);
    doctorWorking($service, [[1, '08:00', '10:00']]);

    $any = app(FindBookableDays::class)->handle($service, null);
    $onlyAna = app(FindBookableDays::class)->handle($service, $ana);

    // The two doctors share 08:00. Together they offer 08:00 to 09:00 (5 times); Ana alone offers 1.
    expect($any['2026-11-02'])->toHaveCount(5)
        ->and($onlyAna['2026-11-02'])->toHaveCount(1);
});

it('gives only empty days for a doctor who does not offer the service', function () {
    $service = Service::factory()->lasting(60)->create();
    $stranger = doctorWorking(Service::factory()->create(), [[1, '08:00', '12:00']]);

    $days = app(FindBookableDays::class)->handle($service, $stranger);

    expect($days)->toHaveCount(31)
        ->and(array_filter($days))->toBe([]);
});
