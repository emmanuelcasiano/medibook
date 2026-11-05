<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\TimeOff;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

final class DeleteTimeOff
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Remove a time off, so its slots can be booked again. It is a plain delete: nothing else points at a time off,
     * and the bookings that were moved or cancelled because of it stay as they are.
     * The caller has already authorized this (DoctorPolicy::update) and checked the time off belongs to the doctor in the URL.
     * `time_off.deleted` is written to the audit log in the same transaction.
     */
    public function handle(User $actor, TimeOff $timeOff): void
    {
        DB::transaction(function () use ($actor, $timeOff): void {
            // Logged before the delete: the logger refuses a subject that is no longer saved. The row keeps the id.
            $this->audit->record($actor, 'time_off.deleted', $timeOff, [
                'doctor_id' => $timeOff->doctor_id,
                'starts_at' => $timeOff->starts_at->toIso8601ZuluString(),
                'ends_at' => $timeOff->ends_at->toIso8601ZuluString(),
            ]);

            $timeOff->delete();
        });
    }
}
