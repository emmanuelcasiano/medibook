<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Doctor;
use App\Models\TimeOff;
use App\Models\User;
use App\Support\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CreateTimeOff
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Block a stretch of time for one doctor. The times are UTC (the caller converts from clinic time).
     * Existing bookings inside the range are not touched and do not stop this: the admin is warned
     * (CountBookingsInTimeOff) and staff move them. Only slots found from now on skip this time.
     * The caller has already authorized this (DoctorPolicy::update) and checked that the end is after the start.
     *
     * `time_off.created` is written to the audit log in the same transaction. The reason is left out of the log: free
     * text such as "sick leave" can be health detail about the doctor, and the row already has it.
     */
    public function handle(User $actor, Doctor $doctor, CarbonImmutable $startsAt, CarbonImmutable $endsAt, ?string $reason): TimeOff
    {
        return DB::transaction(function () use ($actor, $doctor, $startsAt, $endsAt, $reason): TimeOff {
            // The doctor comes from the URL, never from the form, so a time off can't be added to another doctor.
            $timeOff = $doctor->timeOffs()->create([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'reason' => $reason,
            ]);

            $this->audit->record($actor, 'time_off.created', $timeOff, [
                'doctor_id' => $doctor->id,
                'starts_at' => $startsAt->toIso8601ZuluString(),
                'ends_at' => $endsAt->toIso8601ZuluString(),
            ]);

            return $timeOff;
        });
    }
}
