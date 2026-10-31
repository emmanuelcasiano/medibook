<?php

declare(strict_types=1);

use App\Actions\Booking\FindAvailableSlotsForAnyDoctor;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Same "now" as FindAvailableSlotsTest: Friday 30 October 2026, 09:00 in Manila. Monday 2 November is weekday 1.
// The rules for one doctor (time off, bookings, lead time, window) are tested in FindAvailableSlotsTest. These
// tests cover the merge, and that each doctor's own rules still count.
// This Action has no route or policy of its own, so there is no denied-access test. The closest rules are that a
// doctor who does not offer the service, or whose account is switched off, adds nothing. The public booking pages
// (step 6.1) reach it through FindBookableDays. They only read public data, so they have no Policy.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));
});

function findAnyDoctorSlots(Service $service, string $from, ?string $to = null): Collection
{
    return app(FindAvailableSlotsForAnyDoctor::class)->handle($service, slotDay($from), slotDay($to ?? $from));
}

it('merges the slots of every doctor who offers the service, earliest first', function () {
    $service = Service::factory()->lasting(60)->create();
    // The afternoon doctor is created first, so the order can't come from the order of the doctors.
    doctorWorking($service, [[1, '13:00', '14:00']]);
    doctorWorking($service, [[1, '08:00', '09:00']]);

    expect(slotTimes(findAnyDoctorSlots($service, '2026-11-02')))->toBe(['08:00', '13:00']);
});

it('lists a start time once when several doctors can take it', function () {
    $service = Service::factory()->lasting(60)->create();
    doctorWorking($service, [[1, '08:00', '10:00']]);
    doctorWorking($service, [[1, '08:00', '10:00']]);
    doctorWorking($service, [[1, '09:00', '11:00']]);

    // First doctors: 08:00 to 09:00. Third doctor: 09:00 to 10:00. 09:00 is free for all three.
    expect(slotTimes(findAnyDoctorSlots($service, '2026-11-02')))->toBe([
        '08:00', '08:15', '08:30', '08:45', '09:00', '09:15', '09:30', '09:45', '10:00',
    ]);
});

it('merges the days of a range in order', function () {
    $service = Service::factory()->lasting(60)->create();
    doctorWorking($service, [[3, '10:00', '11:00']]);
    doctorWorking($service, [[1, '08:00', '09:00']]);

    expect(slotTimes(findAnyDoctorSlots($service, '2026-11-02', '2026-11-08'), 'Y-m-d H:i'))->toBe([
        '2026-11-02 08:00',
        '2026-11-04 10:00',
    ]);
});

it('returns the slots as UTC instants converted from Manila time', function () {
    $service = Service::factory()->lasting(30)->create();
    doctorWorking($service, [[1, '06:00', '06:30']]);

    $slots = findAnyDoctorSlots($service, '2026-11-02');

    // 06:00 Manila (UTC+8) is 22:00 UTC on the day before.
    expect($slots->map(fn (CarbonImmutable $slot): string => $slot->toIso8601ZuluString())->all())->toBe(['2026-11-01T22:00:00Z']);
});

it('keeps the slot another doctor still has when one doctor is booked', function () {
    $service = Service::factory()->lasting(60)->create();
    $busy = doctorWorking($service, [[1, '08:00', '09:00']]);
    doctorWorking($service, [[1, '08:00', '09:00']]);
    bookingAt($busy, $service, '2026-11-02 08:00');

    expect(slotTimes(findAnyDoctorSlots($service, '2026-11-02')))->toBe(['08:00']);
});

it('removes a slot when every doctor who could take it is booked', function () {
    $service = Service::factory()->lasting(60)->create();
    $first = doctorWorking($service, [[1, '08:00', '09:00']]);
    $second = doctorWorking($service, [[1, '08:00', '09:00']]);
    bookingAt($first, $service, '2026-11-02 08:00');
    bookingAt($second, $service, '2026-11-02 08:00');

    expect(findAnyDoctorSlots($service, '2026-11-02'))->toBeEmpty();
});

it('keeps the slot another doctor still has when one doctor is on time off', function () {
    $service = Service::factory()->lasting(60)->create();
    $away = doctorWorking($service, [[1, '08:00', '09:00']]);
    doctorWorking($service, [[1, '08:00', '09:00']]);
    slotTimeOff($away, '2026-11-02 00:00', '2026-11-03 00:00');

    expect(slotTimes(findAnyDoctorSlots($service, '2026-11-02')))->toBe(['08:00']);
});

it('offers nothing when every doctor is on time off', function () {
    $service = Service::factory()->lasting(60)->create();
    $first = doctorWorking($service, [[1, '08:00', '09:00']]);
    $second = doctorWorking($service, [[1, '08:00', '09:00']]);
    slotTimeOff($first, '2026-11-02 00:00', '2026-11-03 00:00');
    slotTimeOff($second, '2026-11-02 00:00', '2026-11-03 00:00');

    expect(findAnyDoctorSlots($service, '2026-11-02'))->toBeEmpty();
});

it('still applies the lead time, past dates and the booking window to every doctor', function () {
    $service = Service::factory()->lasting(60)->create();
    doctorWorking($service, [[1, '08:00', '09:00'], [5, '08:00', '09:00']]);
    doctorWorking($service, [[1, '08:00', '09:00'], [5, '08:00', '09:00']]);

    // Today is Friday 30 October. Friday 30 October 08:00 is in the past (it is 09:00 now), and Monday
    // 30 November is day 31, beyond the window. The Mondays and Fridays between them are open.
    expect(slotTimes(findAnyDoctorSlots($service, '2026-10-30', '2026-11-30'), 'Y-m-d H:i'))->toBe([
        '2026-11-02 08:00',
        '2026-11-06 08:00',
        '2026-11-09 08:00',
        '2026-11-13 08:00',
        '2026-11-16 08:00',
        '2026-11-20 08:00',
        '2026-11-23 08:00',
        '2026-11-27 08:00',
    ]);
});

it('ignores a doctor who does not offer the service', function () {
    $service = Service::factory()->lasting(60)->create();
    $otherService = Service::factory()->lasting(60)->create();
    doctorWorking($service, [[1, '08:00', '09:00']]);
    doctorWorking($otherService, [[1, '13:00', '17:00']]);

    expect(slotTimes(findAnyDoctorSlots($service, '2026-11-02')))->toBe(['08:00']);
});

it('ignores a doctor whose account is switched off, and offers nothing when they are the only one', function () {
    $service = Service::factory()->lasting(60)->create();
    $active = doctorWorking($service, [[1, '08:00', '09:00']]);
    $retired = doctorWorking($service, [[1, '13:00', '14:00']]);
    $retired->user->forceFill(['is_active' => false])->save();

    expect(slotTimes(findAnyDoctorSlots($service, '2026-11-02')))->toBe(['08:00']);

    $active->user->forceFill(['is_active' => false])->save();

    expect(findAnyDoctorSlots($service, '2026-11-02'))->toBeEmpty();
});

it('offers nothing when no doctor offers the service', function () {
    $service = Service::factory()->lasting(60)->create();
    doctorWorking(Service::factory()->lasting(60)->create(), [[1, '08:00', '17:00']]);

    expect(findAnyDoctorSlots($service, '2026-11-02'))->toBeEmpty();
});

it('offers nothing when the doctors have no working hours', function () {
    $service = Service::factory()->lasting(60)->create();
    doctorWorking($service, []);

    expect(findAnyDoctorSlots($service, '2026-11-02', '2026-11-08'))->toBeEmpty();
});

it('offers nothing for a switched-off service', function () {
    $service = Service::factory()->inactive()->lasting(60)->create();
    doctorWorking($service, [[1, '08:00', '17:00']]);

    expect(findAnyDoctorSlots($service, '2026-11-02'))->toBeEmpty();
});

it('rejects a date range that ends before it starts, even when no doctor offers the service', function () {
    $service = Service::factory()->lasting(60)->create();

    findAnyDoctorSlots($service, '2026-11-03', '2026-11-02');
})->throws(InvalidArgumentException::class, 'The last date must not be before the first date.');

it('loads the doctors with one query however many there are', function () {
    $service = Service::factory()->lasting(60)->create();
    doctorWorking($service, [[1, '08:00', '09:00']]);
    doctorWorking($service, [[1, '08:00', '09:00']]);
    doctorWorking($service, [[1, '08:00', '09:00']]);

    DB::enableQueryLog();
    findAnyDoctorSlots($service, '2026-11-02');
    $queries = collect(DB::getQueryLog())->pluck('query');

    expect($queries->filter(fn (string $sql): bool => str_contains($sql, 'from `doctors`')))->toHaveCount(1);
});
