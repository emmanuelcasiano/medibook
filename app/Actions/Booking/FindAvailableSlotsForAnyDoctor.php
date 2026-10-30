<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class FindAvailableSlotsForAnyDoctor
{
    public function __construct(private readonly FindAvailableSlots $findSlots) {}

    /**
     * The start times where at least one doctor who offers $service is free, from $fromDate to $toDate
     * (both days included). Each doctor is checked by FindAvailableSlots, so time off, active bookings with
     * their buffer, the lead time, the booking window and the Manila-to-UTC conversion are applied there and
     * nowhere else. This only merges the results.
     *
     * A start time that several doctors can take is listed once. The result holds start times only: it does
     * not say which doctor is free, and BookAppointment picks the doctor inside its lock (step 5.1).
     *
     * @param  CarbonImmutable  $fromDate  Any instant on the first day. It is read as a clinic date.
     * @param  CarbonImmutable  $toDate  Any instant on the last day. It is read as a clinic date.
     * @return Collection<int, CarbonImmutable> UTC start times, earliest first, no repeats. Empty if the service is switched off or no doctor offers it.
     *
     * @throws InvalidArgumentException When $toDate is on a day before $fromDate.
     */
    public function handle(Service $service, CarbonImmutable $fromDate, CarbonImmutable $toDate): Collection
    {
        $timezone = (string) config('app.clinic_timezone');

        // Checked here too, so a reversed range throws even when there is no doctor to ask.
        if ($toDate->setTimezone($timezone)->startOfDay()->lt($fromDate->setTimezone($timezone)->startOfDay())) {
            throw new InvalidArgumentException('The last date must not be before the first date.');
        }

        // A switched-off service can't be booked, so it has no slots, whoever offers it.
        if (! $service->is_active) {
            return collect();
        }

        // One query for every doctor who offers the service. A doctor who does not offer it is never asked.
        return $service->doctors()->get()
            ->flatMap(fn ($doctor): Collection => $this->findSlots->handle($service, $doctor, $fromDate, $toDate))
            ->unique(fn (CarbonImmutable $slot): int => $slot->getTimestamp())
            ->sortBy(fn (CarbonImmutable $slot): int => $slot->getTimestamp())
            ->values();
    }
}
