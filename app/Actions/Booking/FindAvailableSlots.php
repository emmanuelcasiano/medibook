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
     * A start time is then removed when:
     * - it is before now plus `medibook.lead_time_hours` (this also removes everything in the past);
     * - its day is more than `medibook.booking_window_days` days ahead of today (the last day of the window stays);
     * - the visit plus its buffer overlaps the doctor's time off;
     * - the visit plus its buffer overlaps an active booking of the doctor (its own buffer is already in
     *   `blocked_until`). Cancelled and no-show bookings are not active, so they free the slot.
     * Touching is not overlapping: a visit may start exactly when a booking's buffer ends, or end exactly when
     * a booking starts.
     *
     * This does not merge doctors (step 4.3).
     *
     * @param  CarbonImmutable  $fromDate  Any instant on the first day. It is read as a clinic date.
     * @param  CarbonImmutable  $toDate  Any instant on the last day. It is read as a clinic date.
     * @return Collection<int, CarbonImmutable> UTC start times, earliest first. Empty if the doctor does not offer the service, or the dates are all in the past or beyond the window.
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

        $now = now();
        $today = $now->setTimezone($timezone)->startOfDay();

        // Cut the range to what can be booked: nothing before today, nothing past the booking window.
        // This also keeps a huge range (year 1900 to 2100) from looping over days that can never have a slot.
        $firstDay = $firstDay->max($today);
        $lastDay = $lastDay->min($today->addDays((int) config('medibook.booking_window_days')));

        if ($lastDay->lt($firstDay)) {
            return collect();
        }

        // A slot must start at least this long from now, so nobody books a visit that starts in the next minutes.
        $earliestStart = $now->addHours((int) config('medibook.lead_time_hours'))->getTimestamp();

        // One query for the whole week's pattern, then the loop below only does date math.
        $rangesByWeekday = $doctor->workingHours()->orderBy('starts_at')->get()->groupBy('weekday');
        $blocked = $this->blockedPeriods($doctor, $firstDay, $lastDay->addDay());

        // Visit plus buffer: the buffer keeps the doctor free for the next patient, so it must fit too.
        $minutesNeeded = $service->duration_minutes + $service->buffer_minutes;
        // A step below 1 would never move forward, so a bad config can't hang the request.
        $step = max(1, (int) config('medibook.slot_step_minutes'));

        $slots = [];

        for ($day = $firstDay; $day->lte($lastDay); $day = $day->addDay()) {
            foreach ($rangesByWeekday->get($day->dayOfWeek) ?? [] as $range) {
                $rangeEnd = $day->setTimeFromTimeString($range->ends_at);

                for ($start = $day->setTimeFromTimeString($range->starts_at); $start->addMinutes($minutesNeeded)->lte($rangeEnd); $start = $start->addMinutes($step)) {
                    $startsAt = $start->getTimestamp();

                    if ($startsAt < $earliestStart) {
                        continue;
                    }

                    if ($this->overlapsAny($blocked, $startsAt, $start->addMinutes($minutesNeeded)->getTimestamp())) {
                        continue;
                    }

                    $slots[] = $start->utc();
                }
            }
        }

        return collect($slots);
    }

    /**
     * The doctor's time off and active bookings (with their buffer) that touch [$from, $until), as
     * [start, end] Unix timestamps. Two queries for the whole range, not one per day or per slot.
     *
     * The dates are turned into UTC first: the query builder writes a date as it is, with no timezone
     * conversion, and the columns hold UTC.
     *
     * @return list<array{int, int}>
     */
    private function blockedPeriods(Doctor $doctor, CarbonImmutable $from, CarbonImmutable $until): array
    {
        $from = $from->utc();
        $until = $until->utc();

        $periods = [];

        $doctor->timeOffs()
            ->where('starts_at', '<', $until)
            ->where('ends_at', '>', $from)
            ->get(['starts_at', 'ends_at'])
            ->each(function ($timeOff) use (&$periods): void {
                $periods[] = [$timeOff->starts_at->getTimestamp(), $timeOff->ends_at->getTimestamp()];
            });

        // Active only (booked, checked in, completed): cancelled and no-show bookings don't hold the slot.
        $doctor->appointments()
            ->active()
            ->where('starts_at', '<', $until)
            ->where('blocked_until', '>', $from)
            ->get(['starts_at', 'blocked_until'])
            ->each(function ($appointment) use (&$periods): void {
                $periods[] = [$appointment->starts_at->getTimestamp(), $appointment->blocked_until->getTimestamp()];
            });

        return $periods;
    }

    /**
     * Same test as the overlap check in BookAppointment: new start < blocked end AND new end > blocked start.
     *
     * @param  list<array{int, int}>  $periods
     */
    private function overlapsAny(array $periods, int $startsAt, int $endsAt): bool
    {
        foreach ($periods as [$periodStart, $periodEnd]) {
            if ($startsAt < $periodEnd && $endsAt > $periodStart) {
                return true;
            }
        }

        return false;
    }
}
