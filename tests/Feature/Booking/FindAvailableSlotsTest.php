<?php

declare(strict_types=1);

use App\Actions\Booking\FindAvailableSlots;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

// 4.1 reads working hours only, so nothing here depends on "now" and no test freezes time.
// There is no route or policy yet, so there is no denied-access test. The public booking screens (Day 6)
// call this Action behind their own Form Request and Policy.
// Dates used below: Monday 2 November 2026 is weekday 1, Sunday 8 November is weekday 0.

/** A clinic date (Asia/Manila) as the Action receives it. */
function slotDay(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date, 'Asia/Manila');
}

/**
 * A doctor who offers $service and works the given ranges.
 *
 * @param  list<array{int, string, string}>  $ranges  [weekday, 'H:i' start, 'H:i' end], clinic time.
 */
function doctorWorking(Service $service, array $ranges): Doctor
{
    $doctor = Doctor::factory()->create();
    $doctor->services()->attach($service);

    foreach ($ranges as [$weekday, $startsAt, $endsAt]) {
        WorkingHour::factory()->for($doctor)->onWeekday($weekday)->between($startsAt, $endsAt)->create();
    }

    return $doctor;
}

/**
 * The slots as clinic-time strings, so a test reads like the doctor's day.
 *
 * @param  Collection<int, CarbonImmutable>  $slots
 * @return list<string>
 */
function slotTimes(Collection $slots, string $format = 'H:i'): array
{
    return $slots->map(fn (CarbonImmutable $slot): string => $slot->setTimezone('Asia/Manila')->format($format))->values()->all();
}

function findSlots(Service $service, Doctor $doctor, string $from, ?string $to = null): Collection
{
    return app(FindAvailableSlots::class)->handle($service, $doctor, slotDay($from), slotDay($to ?? $from));
}

it('lists a start time every 15 minutes across a working range', function () {
    $service = Service::factory()->lasting(30)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '10:00']]);

    expect(slotTimes(findSlots($service, $doctor, '2026-11-02')))->toBe([
        '08:00', '08:15', '08:30', '08:45', '09:00', '09:15', '09:30',
    ]);
});

it('offers no slot across a lunch break', function () {
    $service = Service::factory()->lasting(60)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '12:00'], [1, '13:00', '17:00']]);

    $times = slotTimes(findSlots($service, $doctor, '2026-11-02'));

    expect($times)->toContain('11:00')       // last morning start: ends exactly at 12:00
        ->not->toContain('11:15')            // would run into the lunch break
        ->not->toContain('12:00')
        ->not->toContain('12:45')
        ->toContain('13:00')                 // first afternoon start
        ->toContain('16:00')                 // last afternoon start: ends exactly at 17:00
        ->not->toContain('16:15');
});

it('only lists a start time where the visit and its buffer fit before the range ends', function (int $duration, int $buffer, ?string $lastStart) {
    $service = Service::factory()->lasting($duration, $buffer)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '10:00']]);

    $times = slotTimes(findSlots($service, $doctor, '2026-11-02'));

    expect($times ? end($times) : null)->toBe($lastStart);
})->with([
    'visit only, ends before the range ends' => [30, 0, '09:30'],
    'visit only, ends exactly at the end' => [60, 0, '09:00'],
    'the buffer pushes the last start earlier' => [30, 10, '09:15'],
    'visit plus buffer ends exactly at the end' => [45, 15, '09:00'],
    'not on the 15-minute step, so it rounds down' => [90, 0, '08:30'],
    'the whole range is needed' => [120, 0, '08:00'],
    'longer than the range' => [135, 0, null],
]);

it('counts the 15-minute step from the start of each range', function () {
    $service = Service::factory()->lasting(15)->create();
    $doctor = doctorWorking($service, [[1, '08:10', '09:00']]);

    expect(slotTimes(findSlots($service, $doctor, '2026-11-02')))->toBe(['08:10', '08:25', '08:40']);
});

it('reads the step from config', function () {
    config(['medibook.slot_step_minutes' => 30]);

    $service = Service::factory()->lasting(30)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '10:00']]);

    expect(slotTimes(findSlots($service, $doctor, '2026-11-02')))->toBe(['08:00', '08:30', '09:00', '09:30']);
});

it('returns the slots as UTC instants converted from Manila time', function () {
    $service = Service::factory()->lasting(30)->create();
    // 06:00 Manila (UTC+8) is 22:00 UTC on the day before, so the UTC date differs from the clinic date.
    $doctor = doctorWorking($service, [[1, '06:00', '07:00']]);

    $slots = findSlots($service, $doctor, '2026-11-02');

    expect($slots->map(fn (CarbonImmutable $slot): string => $slot->toIso8601ZuluString())->all())->toBe([
        '2026-11-01T22:00:00Z',
        '2026-11-01T22:15:00Z',
        '2026-11-01T22:30:00Z',
    ]);
});

it('reads the dates as clinic dates, not UTC dates', function () {
    $service = Service::factory()->lasting(30)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '09:00']]);

    // Sunday 20:00 UTC is already Monday 04:00 in Manila, so this asks for Monday.
    $asked = CarbonImmutable::parse('2026-11-01 20:00', 'UTC');
    $slots = app(FindAvailableSlots::class)->handle($service, $doctor, $asked, $asked);

    expect(slotTimes($slots, 'Y-m-d H:i'))->toBe(['2026-11-02 08:00', '2026-11-02 08:15', '2026-11-02 08:30']);
});

it('lists the slots earliest first even when the ranges were saved out of order', function () {
    $service = Service::factory()->lasting(60)->create();
    $doctor = doctorWorking($service, [[1, '13:00', '14:00'], [1, '08:00', '09:00']]);

    expect(slotTimes(findSlots($service, $doctor, '2026-11-02')))->toBe(['08:00', '13:00']);
});

it('does not join two ranges that touch, so a visit can not cross from one into the other', function () {
    $service = Service::factory()->lasting(60)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '12:00'], [1, '12:00', '16:00']]);

    $times = slotTimes(findSlots($service, $doctor, '2026-11-02'));

    expect($times)->toContain('11:00')
        ->not->toContain('11:15')
        ->not->toContain('11:45')
        ->toContain('12:00');
});

it('treats a step below 1 as 1 minute instead of looping forever', function () {
    config(['medibook.slot_step_minutes' => 0]);

    $service = Service::factory()->lasting(15)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '08:20']]);

    expect(slotTimes(findSlots($service, $doctor, '2026-11-02')))->toBe(['08:00', '08:01', '08:02', '08:03', '08:04', '08:05']);
});

it('offers nothing when the doctor has no working hours', function () {
    $service = Service::factory()->lasting(30)->create();
    $doctor = doctorWorking($service, []);

    expect(findSlots($service, $doctor, '2026-11-02', '2026-11-08'))->toBeEmpty();
});

it('offers nothing on a weekday the doctor does not work', function () {
    $service = Service::factory()->lasting(30)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '12:00']]);

    expect(findSlots($service, $doctor, '2026-11-03'))->toBeEmpty(); // Tuesday
});

it('uses the weekday of each date across a range of days, with Sunday as 0', function () {
    $service = Service::factory()->lasting(60)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '09:00'], [0, '10:00', '11:00'], [3, '14:00', '15:00']]);

    // Monday 2 Nov to Sunday 8 Nov: Monday, Wednesday and Sunday only, earliest first.
    expect(slotTimes(findSlots($service, $doctor, '2026-11-02', '2026-11-08'), 'Y-m-d H:i'))->toBe([
        '2026-11-02 08:00',
        '2026-11-04 14:00',
        '2026-11-08 10:00',
    ]);
});

it('offers nothing when the doctor does not offer the service', function () {
    $service = Service::factory()->lasting(30)->create();
    $otherService = Service::factory()->lasting(30)->create();
    $doctor = doctorWorking($otherService, [[1, '08:00', '12:00']]);

    expect(findSlots($service, $doctor, '2026-11-02'))->toBeEmpty();
});

it('uses only the working hours of the doctor asked for', function () {
    $service = Service::factory()->lasting(60)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '09:00']]);
    doctorWorking($service, [[1, '13:00', '17:00']]);

    expect(slotTimes(findSlots($service, $doctor, '2026-11-02')))->toBe(['08:00']);
});

it('rejects a date range that ends before it starts', function () {
    $service = Service::factory()->lasting(30)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '12:00']]);

    findSlots($service, $doctor, '2026-11-03', '2026-11-02');
})->throws(InvalidArgumentException::class, 'The last date must not be before the first date.');

it('lists the slots of a seeded-style week that match the hand count', function () {
    // Mon, Tue, Thu: 08:00-12:00 and 13:00-17:00. A 30-minute visit with a 10-minute buffer needs 40 minutes.
    // Morning: starts 08:00 to 11:15 (last one ends 11:55) = 14 slots. Afternoon: 13:00 to 16:15 = 14 slots.
    // A day has 28 slots, so three working days have 84.
    $service = Service::factory()->lasting(30, 10)->create();
    $doctor = doctorWorking($service, [
        [1, '08:00', '12:00'], [1, '13:00', '17:00'],
        [2, '08:00', '12:00'], [2, '13:00', '17:00'],
        [4, '08:00', '12:00'], [4, '13:00', '17:00'],
    ]);

    $slots = findSlots($service, $doctor, '2026-11-02', '2026-11-08');

    expect($slots)->toHaveCount(84)
        ->and(slotTimes($slots, 'Y-m-d H:i')[0])->toBe('2026-11-02 08:00')
        ->and(slotTimes($slots, 'Y-m-d H:i')[27])->toBe('2026-11-02 16:15');
});
