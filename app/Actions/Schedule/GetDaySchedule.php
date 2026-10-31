<?php

declare(strict_types=1);

namespace App\Actions\Schedule;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class GetDaySchedule
{
    /** The window shown on a day with no working hours and no bookings: 08:00 to 17:00. */
    private const int FALLBACK_START_MINUTE = 480;

    private const int FALLBACK_END_MINUTE = 1020;

    /**
     * What the staff schedule shows for one clinic day: a column per doctor with that day's bookings, and the hours
     * the grid should cover.
     *
     * - The day is read in the clinic timezone and turned into a UTC range for the query, so a booking at 07:30
     *   Manila time (23:30 UTC the day before) lands on the right day.
     * - Cancelled bookings are left out, because they free the slot. No-show bookings stay, so staff see what happened.
     * - Columns are the doctors whose account is on, plus a deactivated doctor who still has a booking that day.
     * - The window is the working hours of the shown doctors and the bookings, rounded out to whole hours, so a
     *   booking outside the usual hours is never cut off. With nothing at all it falls back to 08:00 to 17:00.
     * - A fixed number of queries for the whole day (bookings, doctors, working hours and their eager loads), none
     *   per doctor or per booking.
     *
     * Only the patient's name is loaded: staff and admins don't read health data (PROJECT.md section 3).
     *
     * @param  CarbonImmutable  $date  Any instant on the day. It is read as a clinic date.
     */
    public function handle(CarbonImmutable $date): DaySchedule
    {
        $day = $date->setTimezone((string) config('app.clinic_timezone'))->startOfDay();

        // The columns hold UTC, and the query builder writes a date as it is, so convert the bounds first.
        $from = $day->utc();
        $until = $day->addDay()->utc();

        $appointments = Appointment::query()
            ->where('status', '!=', AppointmentStatus::Cancelled)
            ->where('starts_at', '>=', $from)
            ->where('starts_at', '<', $until)
            ->with(['patient:id,name', 'service:id,name,duration_minutes'])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $doctors = Doctor::query()
            ->with('user')
            ->where(fn (Builder $query) => $query->bookable()->orWhereIn('id', $appointments->pluck('doctor_id')->unique()))
            // The name is on the user, so the sort is done in PHP. PHP's sort is stable, so two doctors with the same
            // name keep this id order and the columns never swap places between page loads.
            ->orderBy('id')
            ->get()
            ->sort(fn (Doctor $a, Doctor $b): int => $a->user->name <=> $b->user->name)
            ->values();

        $workingHours = WorkingHour::query()
            ->whereIn('doctor_id', $doctors->pluck('id'))
            ->where('weekday', $day->dayOfWeek)
            ->get();

        $byDoctor = $appointments->groupBy('doctor_id');
        $hoursByDoctor = $workingHours->groupBy('doctor_id');

        // Every minute that should be on the grid, as [start, end] counted from clinic midnight.
        $spans = [];

        foreach ($workingHours as $range) {
            $spans[] = [$this->minuteOfDay($range->starts_at), $this->minuteOfDay($range->ends_at)];
        }

        foreach ($appointments as $appointment) {
            $spans[] = [
                intdiv($appointment->starts_at->getTimestamp() - $day->getTimestamp(), 60),
                intdiv($appointment->ends_at->getTimestamp() - $day->getTimestamp(), 60),
            ];
        }

        [$startMinute, $endMinute] = $this->window($spans);

        return new DaySchedule(
            $day,
            $startMinute,
            $endMinute,
            $doctors->map(fn (Doctor $doctor): DoctorColumn => new DoctorColumn(
                $doctor,
                $hoursByDoctor->has($doctor->id),
                $byDoctor->get($doctor->id, collect())->values(),
            )),
        );
    }

    /**
     * The whole hours that cover every span, kept inside the day.
     *
     * @param  list<array{int, int}>  $spans
     * @return array{int, int}
     */
    private function window(array $spans): array
    {
        if ($spans === []) {
            return [self::FALLBACK_START_MINUTE, self::FALLBACK_END_MINUTE];
        }

        $start = min(array_column($spans, 0));
        $end = max(array_column($spans, 1));

        return [
            max(0, intdiv($start, 60) * 60),
            min(1440, (int) ceil($end / 60) * 60),
        ];
    }

    /** Working hours are clinic-local 'H:i:s' strings. */
    private function minuteOfDay(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }
}
