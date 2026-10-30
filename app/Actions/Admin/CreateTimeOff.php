<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Doctor;
use App\Models\TimeOff;
use Carbon\CarbonImmutable;

final class CreateTimeOff
{
    /**
     * Block a stretch of time for one doctor. The times are UTC (the caller converts from clinic time).
     * Existing bookings inside the range are not touched and do not stop this: the admin is warned
     * (CountBookingsInTimeOff) and staff move them. Only slots found from now on skip this time.
     * The caller has already authorized this (DoctorPolicy::update) and checked that the end is after the start.
     */
    public function handle(Doctor $doctor, CarbonImmutable $startsAt, CarbonImmutable $endsAt, ?string $reason): TimeOff
    {
        // The doctor comes from the URL, never from the form, so a time off can't be added to another doctor.
        return $doctor->timeOffs()->create([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'reason' => $reason,
        ]);
    }
}
