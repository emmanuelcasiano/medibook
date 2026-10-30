<?php

declare(strict_types=1);

use App\Actions\Booking\FindAvailableSlots;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\TimeOff;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// The lead time and the 30-day window depend on "now", so every test starts on Friday 30 October 2026, 09:00
// in Manila. A test that needs another "now" calls $this->travelTo() again.
// There is no route or policy yet, so there is no denied-access test. The public booking screens (Day 6)
// call this Action behind their own Form Request and Policy.
// Dates used below: Monday 2 November 2026 is weekday 1, Sunday 8 November is weekday 0.
// The last bookable day is today + 30 days: Sunday 29 November 2026.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));
});

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

/**
 * A booking for $doctor starting at a clinic time. $state is an AppointmentFactory state
 * (booked, checkedIn, completed, cancelled, noShow). Its end and buffer come from $service.
 */
function bookingAt(Doctor $doctor, Service $service, string $startsAt, string $state = 'booked'): Appointment
{
    return Appointment::factory()->for($doctor)->for($service)->{$state}()->create([
        'starts_at' => CarbonImmutable::parse($startsAt, 'Asia/Manila')->utc(),
    ]);
}

/** Time off for $doctor between two clinic times. */
function slotTimeOff(Doctor $doctor, string $startsAt, string $endsAt): TimeOff
{
    return TimeOff::factory()->for($doctor)->create([
        'starts_at' => CarbonImmutable::parse($startsAt, 'Asia/Manila')->utc(),
        'ends_at' => CarbonImmutable::parse($endsAt, 'Asia/Manila')->utc(),
    ]);
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

it('removes the slots that overlap time off', function () {
    $service = Service::factory()->lasting(60)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '12:00']]);
    slotTimeOff($doctor, '2026-11-02 09:00', '2026-11-02 10:00');

    // 08:00 ends exactly when the time off starts and 10:00 starts exactly when it ends, so both stay.
    expect(slotTimes(findSlots($service, $doctor, '2026-11-02')))->toBe(['08:00', '10:00', '10:15', '10:30', '10:45', '11:00']);
});

it('removes a slot whose buffer runs into the time off', function () {
    // A 30-minute visit with a 15-minute buffer needs 45 minutes. Time off starts at 09:00.
    $service = Service::factory()->lasting(30, 15)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '12:00']]);
    slotTimeOff($doctor, '2026-11-02 09:00', '2026-11-02 10:00');

    $times = slotTimes(findSlots($service, $doctor, '2026-11-02'));

    expect($times)->toContain('08:15')      // visit and buffer end exactly at 09:00
        ->not->toContain('08:30')           // the visit ends at 09:00, but the buffer would run into the time off
        ->not->toContain('09:45')
        ->toContain('10:00');
});

it('removes every slot on the days a long time off covers', function () {
    $service = Service::factory()->lasting(60)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '09:00'], [2, '08:00', '09:00'], [3, '08:00', '09:00']]);
    slotTimeOff($doctor, '2026-11-02 00:00', '2026-11-04 00:00');

    expect(slotTimes(findSlots($service, $doctor, '2026-11-02', '2026-11-04'), 'Y-m-d H:i'))->toBe(['2026-11-04 08:00']);
});

it('keeps the slots of a doctor whose time off belongs to another doctor', function () {
    $service = Service::factory()->lasting(60)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '09:00']]);
    $otherDoctor = doctorWorking($service, [[1, '08:00', '09:00']]);
    slotTimeOff($otherDoctor, '2026-11-02 00:00', '2026-11-03 00:00');

    expect(slotTimes(findSlots($service, $doctor, '2026-11-02')))->toBe(['08:00']);
});

it('removes the slots that overlap an active booking and its buffer', function () {
    // A 30-minute visit with a 10-minute buffer needs 40 minutes. The booking at 09:00 holds the doctor
    // until 09:40.
    $service = Service::factory()->lasting(30, 10)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '12:00']]);
    bookingAt($doctor, $service, '2026-11-02 09:00');

    // 08:15 needs until 08:55, so it fits before the booking. 08:30 would run into it.
    // 09:30 starts inside the booking's buffer. 09:45 is the first start after the buffer.
    expect(slotTimes(findSlots($service, $doctor, '2026-11-02')))->toBe([
        '08:00', '08:15', '09:45', '10:00', '10:15', '10:30', '10:45', '11:00', '11:15',
    ]);
});

it('uses the buffer of the booked service, not the buffer of the service being searched', function () {
    $booked = Service::factory()->lasting(30, 30)->create(); // holds the doctor from 09:00 to 10:00
    $service = Service::factory()->lasting(15)->create();    // no buffer of its own
    $doctor = doctorWorking($booked, [[1, '08:00', '11:00']]);
    $doctor->services()->attach($service);
    bookingAt($doctor, $booked, '2026-11-02 09:00');

    $times = slotTimes(findSlots($service, $doctor, '2026-11-02'));

    expect($times)->toContain('08:45')       // ends exactly when the booking starts
        ->not->toContain('09:00')
        ->not->toContain('09:45')            // inside the booking's buffer
        ->toContain('10:00');                // the buffer is over
});

it('removes the slot of an active booking and frees the slot of a cancelled or no-show one', function (string $state, bool $holdsSlot) {
    $service = Service::factory()->lasting(60)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '09:00']]);
    bookingAt($doctor, $service, '2026-11-02 08:00', $state);

    expect(slotTimes(findSlots($service, $doctor, '2026-11-02')))->toBe($holdsSlot ? [] : ['08:00']);
})->with([
    'booked' => ['booked', true],
    'checked in' => ['checkedIn', true],
    'completed' => ['completed', true],
    'cancelled' => ['cancelled', false],
    'no-show' => ['noShow', false],
]);

it('keeps the slots of a doctor whose booking belongs to another doctor', function () {
    $service = Service::factory()->lasting(60)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '09:00']]);
    $otherDoctor = doctorWorking($service, [[1, '08:00', '09:00']]);
    bookingAt($otherDoctor, $service, '2026-11-02 08:00');

    expect(slotTimes(findSlots($service, $doctor, '2026-11-02')))->toBe(['08:00']);
});

it('finds a booking whose UTC date is the day before the clinic date', function () {
    $service = Service::factory()->lasting(30)->create();
    $doctor = doctorWorking($service, [[1, '06:00', '07:00']]);
    // 06:00 Manila is 22:00 UTC on 1 November, still inside the asked clinic day of 2 November.
    bookingAt($doctor, $service, '2026-11-02 06:00');

    expect(slotTimes(findSlots($service, $doctor, '2026-11-02')))->toBe(['06:30']);
});

it('removes the slots that start before now plus the lead time', function (string $now, ?string $firstSlot) {
    $this->travelTo(CarbonImmutable::parse("2026-11-02 {$now}", 'Asia/Manila'));

    $service = Service::factory()->lasting(30)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '12:00']]);

    expect(slotTimes(findSlots($service, $doctor, '2026-11-02'))[0] ?? null)->toBe($firstSlot);
})->with([
    'exactly two hours ahead is allowed' => ['06:00', '08:00'],
    'one minute short of the lead time' => ['06:01', '08:15'],
    'later in the morning' => ['08:00', '10:00'],
    'the last slot of the range' => ['09:30', '11:30'],
    'nothing left today' => ['09:31', null],
]);

it('reads the lead time from config', function () {
    config(['medibook.lead_time_hours' => 0]);

    $service = Service::factory()->lasting(30)->create();
    $doctor = doctorWorking($service, [[5, '08:00', '10:00']]); // today is Friday, 09:00

    // The slot at exactly 09:00 is allowed, the ones before now are gone.
    expect(slotTimes(findSlots($service, $doctor, '2026-10-30')))->toBe(['09:00', '09:15', '09:30']);
});

it('offers no slot on a date in the past', function () {
    $service = Service::factory()->lasting(30)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '12:00'], [5, '08:00', '12:00']]);

    expect(findSlots($service, $doctor, '2000-01-01', '2026-10-29'))->toBeEmpty();
});

it('keeps only the future days of a range that starts in the past', function () {
    $service = Service::factory()->lasting(60)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '09:00']]);

    // Mondays 5, 12, 19 and 26 October are past. Only Monday 2 November is left.
    expect(slotTimes(findSlots($service, $doctor, '2026-10-01', '2026-11-02'), 'Y-m-d H:i'))->toBe(['2026-11-02 08:00']);
});

it('offers the last day of the booking window but nothing after it', function () {
    $service = Service::factory()->lasting(60)->create();
    $doctor = doctorWorking($service, [[0, '10:00', '11:00'], [1, '10:00', '11:00']]);

    // Today is Friday 30 October, so the window ends on Sunday 29 November. Monday 30 November is day 31.
    expect(slotTimes(findSlots($service, $doctor, '2026-11-29', '2026-11-30'), 'Y-m-d H:i'))->toBe(['2026-11-29 10:00']);
});

it('offers nothing when every date is beyond the booking window', function () {
    $service = Service::factory()->lasting(60)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '12:00']]);

    expect(findSlots($service, $doctor, '2026-11-30', '2027-03-01'))->toBeEmpty();
});

it('counts the booking window from the clinic date of today, not the UTC date', function () {
    // 17:00 UTC on 30 October is already 01:00 on 31 October in Manila, so the window ends on 30 November.
    $this->travelTo(CarbonImmutable::parse('2026-10-30 17:00', 'UTC'));

    $service = Service::factory()->lasting(60)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '09:00']]);

    expect(slotTimes(findSlots($service, $doctor, '2026-11-30'), 'Y-m-d H:i'))->toBe(['2026-11-30 08:00']);
});

it('reads the booking window from config', function () {
    config(['medibook.booking_window_days' => 7]);

    $service = Service::factory()->lasting(60)->create();
    $doctor = doctorWorking($service, [[5, '10:00', '11:00']]);

    // Fridays: 6 November is day 7, 13 November is day 14.
    expect(slotTimes(findSlots($service, $doctor, '2026-11-06', '2026-11-13'), 'Y-m-d H:i'))->toBe(['2026-11-06 10:00']);
});

it('loads time off and bookings once for the whole range', function () {
    $service = Service::factory()->lasting(60)->create();
    $doctor = doctorWorking($service, [[1, '08:00', '12:00'], [2, '08:00', '12:00'], [3, '08:00', '12:00']]);
    slotTimeOff($doctor, '2026-11-03 08:00', '2026-11-03 09:00');
    bookingAt($doctor, $service, '2026-11-02 08:00');
    bookingAt($doctor, $service, '2026-11-04 09:00');

    DB::enableQueryLog();
    findSlots($service, $doctor, '2026-11-02', '2026-11-29');
    $queries = collect(DB::getQueryLog())->pluck('query');

    expect($queries->filter(fn (string $sql): bool => str_contains($sql, 'from `time_offs`')))->toHaveCount(1)
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'from `appointments`')))->toHaveCount(1);
});
