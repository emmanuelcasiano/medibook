<?php

declare(strict_types=1);

use App\Actions\Booking\CheckSlotIsOffered;
use App\Actions\Booking\FindAvailableSlots;
use App\Actions\Booking\FindAvailableSlotsForAnyDoctor;
use App\Models\Service;
use Carbon\CarbonImmutable;

// The staff walk-in path asks the slot finders for times "from now" instead of "from now plus the 2 hour lead time".
// Every other rule of the finders stays. "Now" is Friday 30 October 2026, 09:00 in Manila. The service lasts 30 minutes
// with a 15 minute buffer, and the doctor works Friday 08:00 to 12:00.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));
    $this->service = Service::factory()->lasting(30, 15)->create();
    $this->doctor = doctorWorking($this->service, [[5, '08:00', '12:00']]);
    $this->friday = slotDay('2026-10-30');
});

it('offers times from now when the lead time is ignored, and from now plus the lead time otherwise', function () {
    $finder = app(FindAvailableSlots::class);

    $fromNow = slotTimes($finder->handle($this->service, $this->doctor, $this->friday, $this->friday, ignoreLeadTime: true));
    $normal = slotTimes($finder->handle($this->service, $this->doctor, $this->friday, $this->friday));

    expect(array_slice($fromNow, 0, 3))->toBe(['09:00', '09:15', '09:30'])
        ->and(array_slice($normal, 0, 2))->toBe(['11:00', '11:15']);
});

it('never offers a time that has already started, even when the lead time is ignored', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:20', 'Asia/Manila'));

    $times = slotTimes(app(FindAvailableSlots::class)->handle($this->service, $this->doctor, $this->friday, $this->friday, ignoreLeadTime: true));

    expect($times[0])->toBe('09:30')
        ->and($times)->not->toContain('09:00', '09:15');
});

it('still removes a time that clashes with a booking or its buffer when the lead time is ignored', function () {
    // 09:30 to 10:00 plus the 15 minute buffer blocks the doctor until 10:15. A visit at 09:00 would run into it.
    bookingAt($this->doctor, $this->service, '2026-10-30 09:30');

    $times = slotTimes(app(FindAvailableSlots::class)->handle($this->service, $this->doctor, $this->friday, $this->friday, ignoreLeadTime: true));

    expect($times[0])->toBe('10:15')
        ->and($times)->not->toContain('09:00', '09:15', '09:30', '10:00');
});

it('passes the option on when "Any doctor" merges the doctors', function () {
    $finder = app(FindAvailableSlotsForAnyDoctor::class);

    expect(slotTimes($finder->handle($this->service, $this->friday, $this->friday, ignoreLeadTime: true))[0])->toBe('09:00')
        ->and(slotTimes($finder->handle($this->service, $this->friday, $this->friday))[0])->toBe('11:00');
});

it('checks a time against the same rule that offered it', function () {
    $isOffered = app(CheckSlotIsOffered::class);
    $nineThirty = CarbonImmutable::parse('2026-10-30 09:30', 'Asia/Manila')->utc();
    $eightFortyFive = CarbonImmutable::parse('2026-10-30 08:45', 'Asia/Manila')->utc();

    expect($isOffered->handle($this->service, $this->doctor, $nineThirty, ignoreLeadTime: true))->toBeTrue()
        ->and($isOffered->handle($this->service, $this->doctor, $nineThirty))->toBeFalse()
        ->and($isOffered->handle($this->service, null, $nineThirty, ignoreLeadTime: true))->toBeTrue()
        ->and($isOffered->handle($this->service, null, $nineThirty))->toBeFalse()
        ->and($isOffered->handle($this->service, $this->doctor, $eightFortyFive, ignoreLeadTime: true))->toBeFalse();
});
