<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use Carbon\CarbonImmutable;

/**
 * What the staff "Book for patient" picker offers for one follow-up: the days of its window that can be booked, and the
 * free start times of the day staff are looking at.
 */
final readonly class FollowUpBookingOptions
{
    /**
     * @param  array<string, int>  $days  Clinic date ('Y-m-d') => how many free times that day has, in date order.
     * @param  list<CarbonImmutable>  $slots  UTC start times of $selectedDate, earliest first.
     * @param  string|null  $unavailable  Why nothing can be offered (a doctor or visit type switched off), else null.
     */
    public function __construct(
        public array $days,
        public ?string $selectedDate,
        public array $slots,
        public ?string $unavailable = null,
    ) {}
}
