<?php

declare(strict_types=1);

namespace App\Actions\Schedule;

use App\Actions\Booking\FindAvailableSlots;
use App\Actions\Booking\FindAvailableSlotsForAnyDoctor;
use App\Models\Service;
use Carbon\CarbonImmutable;

final class FindWalkInOptions
{
    /** The nearest free times shown in the dialog. A walk-in takes one of the first few, so a long list only slows the desk. */
    public const int SLOT_LIMIT = 12;

    public function __construct(
        private readonly FindAvailableSlots $findSlots,
        private readonly FindAvailableSlotsForAnyDoctor $findSlotsForAnyDoctor,
    ) {}

    /**
     * The visit types, and, once staff pick one, its doctors and today's nearest free times (PROJECT.md F2, F7).
     *
     * - The times come from the slot finders with the lead time off, so they start from now. Every other rule (working
     *   hours, time off, buffers, overlaps) is the finders' own. Only today is looked at.
     * - A doctor id that is not in the service's list (unknown, switched off, does not offer it) gives no times and says
     *   nothing about why, like the Move picker. `$anyDoctor` merges the doctors' times and ignores `$doctorId`.
     * - A service that is not bookable (switched off, or nobody offers it) gives no doctors and no times.
     *
     * Three small queries for the lists and the finders' own for the times. The dialog asks only when a choice changes.
     */
    public function handle(?int $serviceId, ?int $doctorId, bool $anyDoctor): WalkInOptions
    {
        // Whole rows, like the Move picker: the finders read the duration, the buffer and the switch, and strict mode throws
        // on a column that was not loaded, so a column list here would break the day a finder reads one more.
        $services = Service::query()->bookable()->orderBy('name')->orderBy('id')->get();

        $service = $serviceId === null ? null : $services->firstWhere('id', $serviceId);

        if ($service === null) {
            return new WalkInOptions($services, collect(), collect());
        }

        $doctors = $service->doctors()->bookable()->with('user:id,name')->orderedByName()->get();

        $today = CarbonImmutable::now((string) config('app.clinic_timezone'));

        if ($anyDoctor) {
            $slots = $this->findSlotsForAnyDoctor->handle($service, $today, $today, ignoreLeadTime: true);
        } else {
            $doctor = $doctorId === null ? null : $doctors->firstWhere('id', $doctorId);
            $slots = $doctor === null
                ? collect()
                : $this->findSlots->handle($service, $doctor, $today, $today, ignoreLeadTime: true);
        }

        return new WalkInOptions($services, $doctors, $slots->take(self::SLOT_LIMIT)->values());
    }
}
