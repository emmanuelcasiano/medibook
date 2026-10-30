<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Doctor;
use Illuminate\Support\Facades\DB;

final class UpdateWorkingHours
{
    /**
     * Replace the doctor's whole weekly schedule with the given ranges. An empty list makes the doctor
     * unavailable every day. Times are clinic-local 'H:i' and are stored as 'H:i:s'.
     * Existing appointments are not touched: only slots that are found from now on follow the new hours.
     * The caller has already authorized this (DoctorPolicy::update) and checked that no ranges overlap.
     *
     * @param  list<array{weekday: int, starts_at: string, ends_at: string}>  $ranges
     */
    public function handle(Doctor $doctor, array $ranges): Doctor
    {
        // One transaction, so a failure while inserting can't leave the doctor with no hours at all.
        return DB::transaction(function () use ($doctor, $ranges): Doctor {
            // Lock the doctor row. A second save for this doctor waits here until this one ends, so two admins
            // saving at the same moment can't both delete and then both insert, which would double the ranges.
            Doctor::query()->whereKey($doctor->id)->lockForUpdate()->first();

            $doctor->workingHours()->delete();

            $doctor->workingHours()->createMany(
                array_map(fn (array $range): array => [
                    'weekday' => $range['weekday'],
                    'starts_at' => $range['starts_at'].':00',
                    'ends_at' => $range['ends_at'].':00',
                ], $ranges),
            );

            return $doctor;
        });
    }
}
