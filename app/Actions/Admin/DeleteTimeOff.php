<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\TimeOff;

final class DeleteTimeOff
{
    /**
     * Remove a time off, so its slots can be booked again. It is a plain delete: nothing else points at a time off,
     * and the bookings that were moved or cancelled because of it stay as they are.
     * The caller has already authorized this (DoctorPolicy::update) and checked the time off belongs to the doctor in the URL.
     */
    public function handle(TimeOff $timeOff): void
    {
        $timeOff->delete();
    }
}
