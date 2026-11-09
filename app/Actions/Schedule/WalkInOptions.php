<?php

declare(strict_types=1);

namespace App\Actions\Schedule;

use App\Models\Doctor;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the walk-in dialog can offer so far: the visit types that can be booked now; the bookable doctors who offer the
 * picked service (with `user` loaded), empty until one is picked; and today's free start times from now (UTC, earliest
 * first), empty until a service and a doctor, or "Any doctor", are picked.
 */
final readonly class WalkInOptions
{
    /**
     * @param  Collection<int, Service>  $services
     * @param  Collection<int, Doctor>  $doctors
     * @param  Collection<int, CarbonImmutable>  $slots
     */
    public function __construct(
        public Collection $services,
        public Collection $doctors,
        public Collection $slots,
    ) {}
}
