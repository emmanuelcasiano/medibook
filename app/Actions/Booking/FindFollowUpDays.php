<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Models\Doctor;
use App\Models\FollowUp;
use Carbon\CarbonImmutable;

final class FindFollowUpDays
{
    public function __construct(private readonly FindBookableDays $findDays) {}

    /**
     * The days a patient can pick for $followUp: the normal booking days (FindBookableDays, so every slot rule still
     * applies) cut down to the days of the follow-up window. $doctor is the asking doctor, or null for "Any doctor".
     *
     * The booking window is 30 days, so a follow-up window that starts later than that has no days yet, and one that
     * ends later is cut at the 30th day. A day without a free time stays in the list, empty, like on the public pages.
     *
     * @return array<string, list<CarbonImmutable>> Clinic date ('Y-m-d') => UTC start times, earliest first.
     */
    public function handle(FollowUp $followUp, ?Doctor $doctor): array
    {
        return array_filter(
            $this->findDays->handle($followUp->service, $doctor),
            fn (string $date): bool => $date >= $followUp->window_starts_on->toDateString()
                && $date <= $followUp->window_ends_on->toDateString(),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
