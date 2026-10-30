<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Doctor;
use App\Models\TimeOff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class CountBookingsInTimeOff
{
    /**
     * For each time off, count the doctor's bookings that overlap it, so the admin sees the warning
     * ("3 bookings inside") before staff have to move them. Only bookings that hold a slot and have not
     * ended yet count: a cancelled or no-show booking needs no action, and neither does a visit that is over.
     * Touching times (a visit ending exactly when the time off starts) are not an overlap.
     * It returns a number only, never patient data. The caller has already authorized this (DoctorPolicy::update).
     *
     * @param  Collection<int, TimeOff>  $timeOffs  Time off of this doctor.
     * @return array<int, int> Time off id => number of bookings inside it.
     */
    public function handle(Doctor $doctor, Collection $timeOffs): array
    {
        if ($timeOffs->isEmpty()) {
            return [];
        }

        $from = $timeOffs->reduce(
            fn (?CarbonImmutable $earliest, TimeOff $timeOff): CarbonImmutable => $earliest === null || $timeOff->starts_at->lt($earliest) ? $timeOff->starts_at : $earliest,
        );
        $to = $timeOffs->reduce(
            fn (?CarbonImmutable $latest, TimeOff $timeOff): CarbonImmutable => $latest === null || $timeOff->ends_at->gt($latest) ? $timeOff->ends_at : $latest,
        );

        // One query for the whole list, narrowed to the span the listed time off covers. The counting is done in PHP,
        // so showing 10 rows costs 1 query, not 10.
        $bookings = $doctor->appointments()
            ->active()
            ->where('ends_at', '>', now())
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->get(['id', 'starts_at', 'ends_at']);

        $counts = [];

        foreach ($timeOffs as $timeOff) {
            $counts[$timeOff->id] = $bookings
                ->filter(fn ($booking): bool => $booking->starts_at->lt($timeOff->ends_at) && $booking->ends_at->gt($timeOff->starts_at))
                ->count();
        }

        return $counts;
    }
}
