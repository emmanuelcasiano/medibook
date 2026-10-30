<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Models\Doctor;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class FindAvailableSlots
{
    /**
     * The start times where a visit for $service fits in the doctor's working hours, from $fromDate to $toDate
     * (both days included). Working hours are clinic-local, so the days are worked out in the clinic timezone
     * and each start time comes back as a UTC instant, ready to store or compare.
     *
     * A start time is offered every `medibook.slot_step_minutes`, counted from the start of each working range.
     * It fits when the visit plus its buffer ends inside the same range, so a lunch break (two ranges) never
     * gets a visit that crosses it.
     *
     * This step only reads the working hours. It does not yet remove time off, bookings, the lead time or the
     * booking window (step 4.2), and it does not merge doctors (step 4.3).
     *
     * @param  CarbonImmutable  $fromDate  Any instant on the first day. It is read as a clinic date.
     * @param  CarbonImmutable  $toDate  Any instant on the last day. It is read as a clinic date.
     * @return Collection<int, CarbonImmutable> UTC start times, earliest first. Empty if the doctor does not offer the service.
     *
     * @throws InvalidArgumentException When $toDate is on a day before $fromDate.
     */
    public function handle(Service $service, Doctor $doctor, CarbonImmutable $fromDate, CarbonImmutable $toDate): Collection
    {
        $timezone = (string) config('app.clinic_timezone');
        $firstDay = $fromDate->setTimezone($timezone)->startOfDay();
        $lastDay = $toDate->setTimezone($timezone)->startOfDay();

        if ($lastDay->lt($firstDay)) {
            throw new InvalidArgumentException('The last date must not be before the first date.');
        }

        // A doctor only gets slots for a service they offer, so a wrong service/doctor pair can't be booked.
        if (! $doctor->services()->whereKey($service->id)->exists()) {
            return collect();
        }

        // One query for the whole week's pattern, then the loop below only does date math.
        $rangesByWeekday = $doctor->workingHours()->orderBy('starts_at')->get()->groupBy('weekday');

        // Visit plus buffer: the buffer keeps the doctor free for the next patient, so it must fit too.
        $minutesNeeded = $service->duration_minutes + $service->buffer_minutes;
        // A step below 1 would never move forward, so a bad config can't hang the request.
        $step = max(1, (int) config('medibook.slot_step_minutes'));

        $slots = [];

        for ($day = $firstDay; $day->lte($lastDay); $day = $day->addDay()) {
            foreach ($rangesByWeekday->get($day->dayOfWeek) ?? [] as $range) {
                $rangeEnd = $day->setTimeFromTimeString($range->ends_at);

                for ($start = $day->setTimeFromTimeString($range->starts_at); $start->addMinutes($minutesNeeded)->lte($rangeEnd); $start = $start->addMinutes($step)) {
                    $slots[] = $start->utc();
                }
            }
        }

        return collect($slots);
    }
}
