<?php

declare(strict_types=1);

namespace App\Http\Resources\Schedule;

use App\Actions\Schedule\DaySchedule;
use App\Actions\Schedule\DoctorColumn;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * The staff day schedule as the page receives it. Times of a booking are given twice: as UTC instants, and as
 * minutes from clinic midnight, so the grid can place a block without doing time zone math in the browser.
 *
 * Only the patient's name is sent: no email, birth date, intake or summary. The check-in time is sent too: a moment
 * in time says nothing about health. Staff (not admins) also get two booleans
 * per booking, `intakeMissing` and `emergencyFlag`. Call `->resolve()` to get the plain array for an Inertia prop.
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
        // Asked once for the whole page. An admin gets a schedule with no intake or emergency keys at all.
        $withIntakeStatus = Gate::allows('viewIntakeStatus', Appointment::class);

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
                        ->map(fn (Appointment $appointment): array => $this->appointment($appointment, $withIntakeStatus))
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function appointment(Appointment $appointment, bool $withIntakeStatus): array
    {
        $dayStart = $this->date->getTimestamp();

        $row = [
            'ulid' => $appointment->ulid,
            'startsAt' => $appointment->starts_at->toIso8601ZuluString(),
            'endsAt' => $appointment->ends_at->toIso8601ZuluString(),
            'offsetMinutes' => intdiv($appointment->starts_at->getTimestamp() - $dayStart, 60),
            'durationMinutes' => intdiv($appointment->ends_at->getTimestamp() - $appointment->starts_at->getTimestamp(), 60),
            'patientName' => $appointment->patient->name,
            'serviceName' => $appointment->service->name,
            'status' => $appointment->status->value,
            'statusLabel' => $appointment->status->label(),
            // A moment in time, not health data, so staff and admins both get it. The waiting strip counts from it.
            'checkedInAt' => $appointment->checked_in_at?->toIso8601ZuluString(),
        ];

        if ($withIntakeStatus) {
            // Two yes/no facts, never the answers. The intake can be edited only until check-in, so a missing one is
            // worth a warning only while the booking is still `booked`.
            $row['intakeMissing'] = $appointment->status === AppointmentStatus::Booked && ! $appointment->intake_exists;
            $row['emergencyFlag'] = $appointment->emergency_flag;
        }

        return $row;
    }
}
