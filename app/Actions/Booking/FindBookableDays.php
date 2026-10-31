<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Models\Doctor;
use App\Models\Service;
use Carbon\CarbonImmutable;

final class FindBookableDays
{
    public function __construct(
        private readonly FindAvailableSlots $findSlots,
        private readonly FindAvailableSlotsForAnyDoctor $findSlotsForAnyDoctor,
    ) {}

    /**
     * The first and last clinic day a patient can pick, both at 00:00 clinic time: today, and today plus
     * `medibook.booking_window_days`. This is the same window FindAvailableSlots cuts to, so a day inside it
     * is a day the slot finder may fill, and a day outside it never has a slot.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function window(): array
    {
        $today = now()->setTimezone((string) config('app.clinic_timezone'))->startOfDay();

        return [$today, $today->addDays((int) config('medibook.booking_window_days'))];
    }

    /**
     * Every day of the window with its free start times. A day without a free time is in the result too
     * (with an empty list), so the page can show it as closed instead of leaving a hole in the calendar.
     *
     * The slot rules (time off, bookings, lead time, buffers) all live in the two finders. This only asks
     * them once for the whole window and groups the answer by clinic day. $doctor null means "Any doctor".
     *
     * @return array<string, list<CarbonImmutable>> Clinic date ('Y-m-d') => UTC start times, earliest first. Days are in date order.
     */
    public function handle(Service $service, ?Doctor $doctor): array
    {
        [$firstDay, $lastDay] = $this->window();

        $slots = $doctor === null
            ? $this->findSlotsForAnyDoctor->handle($service, $firstDay, $lastDay)
            : $this->findSlots->handle($service, $doctor, $firstDay, $lastDay);

        $days = [];

        for ($day = $firstDay; $day->lte($lastDay); $day = $day->addDay()) {
            $days[$day->format('Y-m-d')] = [];
        }

        $timezone = (string) config('app.clinic_timezone');

        foreach ($slots as $slot) {
            $days[$slot->setTimezone($timezone)->format('Y-m-d')][] = $slot;
        }

        return $days;
    }
}
