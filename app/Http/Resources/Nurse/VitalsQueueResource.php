<?php

declare(strict_types=1);

namespace App\Http\Resources\Nurse;

use App\Models\Appointment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The nurse's triage queue as the page receives it, in two groups: patients who still need vitals, and patients whose
 * vitals are taken and who wait for the doctor (kept so a typo can be corrected). Each list keeps the order it arrived
 * in, which is oldest check-in first.
 *
 * Only what the nurse needs to find the patient: name, times, doctor and service, and whether vitals were taken. No
 * intake, emergency flag, summary, note, age or birth date, and no reading. The caller must load `patient`,
 * `doctor.user` and `service` first (strict mode throws on a lazy load). Call `->resolve()` to get the plain array for
 * an Inertia prop.
 *
 * @mixin Collection<int, Appointment>
 */
final class VitalsQueueResource extends JsonResource
{
    /**
     * @return array{needsVitals: array<int, array<string, mixed>>, vitalsTaken: array<int, array<string, mixed>>}
     */
    public function toArray(Request $request): array
    {
        /** @var Collection<int, Appointment> $appointments */
        $appointments = $this->resource;

        // The mark, not the record: whether vitals were taken is read from `vitals_taken_at`, so no reading is loaded.
        $taken = fn (Appointment $appointment): bool => $appointment->vitals_taken_at !== null;

        return [
            'needsVitals' => $appointments->reject($taken)->map(fn (Appointment $appointment): array => $this->row($appointment))->values()->all(),
            'vitalsTaken' => $appointments->filter($taken)->map(fn (Appointment $appointment): array => $this->row($appointment))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function row(Appointment $appointment): array
    {
        return [
            'ulid' => $appointment->ulid,
            'startsAt' => $appointment->starts_at->toIso8601ZuluString(),
            'checkedInAt' => $appointment->checked_in_at?->toIso8601ZuluString(),
            'patientName' => $appointment->patient->name,
            'doctorName' => $appointment->doctor->user->name,
            'serviceName' => $appointment->service->name,
        ];
    }
}
