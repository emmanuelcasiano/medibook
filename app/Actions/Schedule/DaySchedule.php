<?php

declare(strict_types=1);

namespace App\Actions\Schedule;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One clinic day for the staff schedule: a column per doctor and the window of hours the grid should cover.
 */
final readonly class DaySchedule
{
    /**
     * @param  CarbonImmutable  $date  The clinic day at 00:00 clinic time.
     * @param  int  $startMinute  First minute shown, counted from clinic midnight. Always on the hour.
     * @param  int  $endMinute  Last minute shown, counted from clinic midnight. Always on the hour.
     * @param  Collection<int, DoctorColumn>  $columns  In doctor name order.
     */
    public function __construct(
        public CarbonImmutable $date,
        public int $startMinute,
        public int $endMinute,
        public Collection $columns,
    ) {}
}
