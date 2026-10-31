<?php

declare(strict_types=1);

namespace App\Http\Resources\Schedule;

use App\Actions\Schedule\DaySchedule;
use App\Actions\Schedule\DoctorColumn;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The staff day schedule as the page receives it. Times of a booking are given twice: as UTC instants, and as
 * minutes from clinic midnight, so the grid can place a block without doing time zone math in the browser.
 *
 * Only the patient's name is sent: no email, birth date, intake or summary. Call `->resolve()` to get the plain
 * array for an Inertia prop.
 *
 * @mixin DaySchedule
 */
final class DayScheduleResource extends JsonResource
{
    /**
     * @return array{
     *     date: string,
     *     previousDate: string,
     *     nextDate: string,
     *     today: string,
     *     startMinute: int,
     *     endMinute: int,
     *     stepMinutes: int,
     *     doctors: array<int, array<string, mixed>>,
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'date' => $this->date->format('Y-m-d'),
            'previousDate' => $this->date->subDay()->format('Y-m-d'),
            'nextDate' => $this->date->addDay()->format('Y-m-d'),
            'today' => CarbonImmutable::now($this->date->getTimezone())->format('Y-m-d'),
            'startMinute' => $this->startMinute,
            'endMinute' => $this->endMinute,
            'stepMinutes' => max(1, (int) config('medibook.slot_step_minutes')),
            'doctors' => $this->columns
                ->map(fn (DoctorColumn $column): array => [
                    'id' => $column->doctor->id,
                    'name' => $column->doctor->user->name,
                    'specialty' => $column->doctor->specialty,
                    // A deactivated doctor can still have bookings that day, so the page marks the column.
                    'isActive' => $column->doctor->user->is_active,
                    'isWorking' => $column->isWorking,
                    'appointments' => $column->appointments
                        ->map(fn (Appointment $appointment): array => $this->appointment($appointment))
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function appointment(Appointment $appointment): array
    {
        $dayStart = $this->date->getTimestamp();

        return [
            'ulid' => $appointment->ulid,
            'startsAt' => $appointment->starts_at->toIso8601ZuluString(),
            'endsAt' => $appointment->ends_at->toIso8601ZuluString(),
            'offsetMinutes' => intdiv($appointment->starts_at->getTimestamp() - $dayStart, 60),
            'durationMinutes' => intdiv($appointment->ends_at->getTimestamp() - $appointment->starts_at->getTimestamp(), 60),
            'patientName' => $appointment->patient->name,
            'serviceName' => $appointment->service->name,
            'status' => $appointment->status->value,
            'statusLabel' => $appointment->status->label(),
        ];
    }
}
