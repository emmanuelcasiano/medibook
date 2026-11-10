<?php

declare(strict_types=1);

namespace App\Actions\Triage;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

final class ListVitalsQueue
{
    /**
     * The patients who are checked in today, oldest check-in first, for the nurse's queue.
     *
     * - "Today" is the clinic day (Asia/Manila), turned into a UTC range for the query, so a visit at 07:30 Manila
     *   time (23:30 UTC the day before) is on the right day.
     * - Only `checked_in` appointments: a visit that is finished, cancelled or a no-show is closed for vitals.
     * - The queue says who is waiting and whether vitals were taken, from `vitals_taken_at`. The readings themselves
     *   are never loaded, so no health data is read or decrypted to draw a list. It also never loads the intake, the
     *   emergency flag, the birth date or any note: the nurse needs a name and a place in the line.
     * - A fixed number of queries for the whole queue. It is bounded by one clinic day, so it is not paginated.
     *
     * The caller says which day "today" is, so the page and the list can't disagree across midnight.
     *
     * @param  CarbonImmutable  $date  Any instant on the day. It is read as a clinic date.
     * @return Collection<int, Appointment>
     */
    public function handle(CarbonImmutable $date): Collection
    {
        $day = $date->setTimezone((string) config('app.clinic_timezone'))->startOfDay();

        // The columns hold UTC, and the query builder writes a date as it is, so convert the bounds first.
        return Appointment::query()
            // The foreign keys are there for the eager loads below. The emergency flag and the cancel columns are not.
            ->select(['id', 'ulid', 'patient_id', 'doctor_id', 'service_id', 'status', 'starts_at', 'checked_in_at', 'vitals_taken_at'])
            ->where('status', AppointmentStatus::CheckedIn)
            ->where('starts_at', '>=', $day->utc())
            ->where('starts_at', '<', $day->addDay()->utc())
            ->with([
                'patient:id,name',
                'doctor:id,user_id',
                'doctor.user:id,name',
                'service:id,name',
            ])
            ->orderBy('checked_in_at')
            ->orderBy('id')
            ->get();
    }
}
