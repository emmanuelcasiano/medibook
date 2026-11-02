<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

final class ListTodaysAppointments
{
    /**
     * The doctor's own appointments for today, earliest first.
     *
     * - "Today" is the clinic day (Asia/Manila), turned into a UTC range for the query, so a visit at 07:30 Manila
     *   time (23:30 UTC the day before) is on the right day.
     * - The doctor comes from the signed-in user, never from the request, so another doctor's bookings can't be asked
     *   for. A user with no doctor profile gets an empty list.
     * - Cancelled bookings are left out, because they free the slot. No-show bookings stay, as on the staff schedule.
     * - A fixed number of queries for the whole day. The intake and its summary are loaded with a column list that
     *   leaves out the encrypted `answers` and `summary`, so no health text is read or decrypted for a list that
     *   only needs to know "filled or not" and the summary status.
     *
     * The caller says which day "today" is, so the page can show the same date it asked for. Asking for "now" here
     * as well could give a different day for a request that crosses midnight.
     *
     * @param  CarbonImmutable  $date  Any instant on the day. It is read as a clinic date.
     * @return Collection<int, Appointment>
     */
    public function handle(User $doctor, CarbonImmutable $date): Collection
    {
        $doctorId = $doctor->doctorId();

        if ($doctorId === null) {
            return new Collection;
        }

        $day = $date->setTimezone((string) config('app.clinic_timezone'))->startOfDay();

        // The columns hold UTC, and the query builder writes a date as it is, so convert the bounds first.
        return Appointment::query()
            ->where('doctor_id', $doctorId)
            ->where('status', '!=', AppointmentStatus::Cancelled)
            ->where('starts_at', '>=', $day->utc())
            ->where('starts_at', '<', $day->addDay()->utc())
            ->with([
                'patient:id,name',
                'service:id,name,duration_minutes',
                'intake:id,appointment_id',
                'intake.summary:id,intake_id,status,reviewed_at',
            ])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }
}
