<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Models\Doctor;
use App\Models\Service;
use Carbon\CarbonImmutable;

final class CheckSlotIsOffered
{
    public function __construct(
        private readonly FindAvailableSlots $findSlots,
        private readonly FindAvailableSlotsForAnyDoctor $findSlotsForAnyDoctor,
    ) {}

    /**
     * Whether $startsAt is a start time the slot finders offer right now. $doctor null means "Any doctor".
     *
     * BookAppointment does not re-check working hours, time off or the lead time, so the booking route asks here
     * first. A time typed into the address bar (3 a.m., yesterday, during a lunch break) is not in the list, so it
     * is refused. The finders are asked for one clinic day only, which keeps the check cheap.
     */
    public function handle(Service $service, ?Doctor $doctor, CarbonImmutable $startsAt): bool
    {
        $day = $startsAt->setTimezone((string) config('app.clinic_timezone'));

        $slots = $doctor === null
            ? $this->findSlotsForAnyDoctor->handle($service, $day, $day)
            : $this->findSlots->handle($service, $doctor, $day, $day);

        return $slots->contains(fn (CarbonImmutable $slot): bool => $slot->getTimestamp() === $startsAt->getTimestamp());
    }
}
