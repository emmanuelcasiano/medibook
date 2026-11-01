<?php

declare(strict_types=1);

namespace App\Actions\Schedule;

use App\Actions\Booking\FindAvailableSlots;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Service;
use Carbon\CarbonImmutable;

final class FindMoveOptions
{
    public function __construct(private readonly FindAvailableSlots $findSlots) {}

    /**
     * The doctors $appointment can be moved to, and, once staff have picked a doctor and a day, the free start times.
     *
     * - The service stays the same, so only bookable doctors who offer it are listed. This includes the booking's
     *   own doctor, since a move may only change the time.
     * - The times come from FindAvailableSlots, the same finder the patient pages use, so every rule (working hours,
     *   time off, buffers, the 2 hour lead time and the 30 day window) is the same. The booking itself is passed as
     *   ignored: it is about to be cancelled, so its own time must not look taken (a 15 minute shift is a normal move).
     * - A doctor id that is not in the list (unknown, switched off, wrong service) gives no times, and says nothing
     *   about why.
     *
     * Two queries for the doctors, and the finder's own queries for one doctor and one day. The caller asks for this
     * only when the panel's Move section is open, so a normal page load never pays for it.
     *
     * @param  CarbonImmutable|null  $day  Any instant on the clinic day to look at. Null until staff pick one.
     */
    public function handle(Appointment $appointment, ?int $doctorId, ?CarbonImmutable $day): MoveOptions
    {
        // The schedule loads only a few columns of the service. The finder needs the buffer too, so read it whole.
        $service = Service::query()->findOrFail($appointment->service_id);

        // The name is on the user, so the sort is done in PHP, like the schedule columns. PHP's sort is stable.
        $doctors = $service->doctors()->bookable()->with('user:id,name')->orderBy('doctors.id')->get()
            ->sort(fn (Doctor $a, Doctor $b): int => $a->user->name <=> $b->user->name)
            ->values();

        $doctor = $doctorId === null ? null : $doctors->firstWhere('id', $doctorId);

        return new MoveOptions(
            $doctors,
            $doctor === null || $day === null
                ? collect()
                : $this->findSlots->handle($service, $doctor, $day, $day, $appointment),
        );
    }
}
