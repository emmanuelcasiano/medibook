<?php

declare(strict_types=1);

namespace App\Actions\Schedule;

use App\Models\Doctor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the staff "Move" picker offers for one booking: the doctors it can go to, and the free start times of the
 * doctor and day staff have picked so far.
 */
final readonly class MoveOptions
{
    /**
     * @param  Collection<int, Doctor>  $doctors  Bookable doctors who offer the booking's service, in name order. The user is loaded.
     * @param  Collection<int, CarbonImmutable>  $slots  UTC start times, earliest first. Empty until a doctor and a day are picked.
     */
    public function __construct(
        public Collection $doctors,
        public Collection $slots,
    ) {}
}
