<?php

declare(strict_types=1);

namespace App\Actions\Schedule;

use App\Models\Appointment;
use App\Models\Doctor;
use Illuminate\Support\Collection;

/**
 * One doctor's column of the day schedule.
 */
final readonly class DoctorColumn
{
    /**
     * @param  Doctor  $doctor  With the `user` relation loaded.
     * @param  bool  $isWorking  Whether the doctor has working hours on this weekday.
     * @param  Collection<int, Appointment>  $appointments  The day's bookings in time order, with `patient` and `service` loaded.
     */
    public function __construct(
        public Doctor $doctor,
        public bool $isWorking,
        public Collection $appointments,
    ) {}
}
