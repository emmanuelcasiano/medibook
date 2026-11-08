<?php

declare(strict_types=1);

namespace App\Http\Resources\Doctor;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * The header of the doctor's appointment page, as the page receives it. The caller must load `patient` (with
 * `birth_date`), `service` and `followUp` first (strict mode throws on a lazy load). The patient is the first name and the age in
 * years only: no surname, email or birth date. No intake or summary text is here; those have their own resources, so
 * they are built only after the Policy has opened them. Call `->resolve()` to get the plain array for an Inertia prop.
 *
 * @mixin Appointment
 */
final class AppointmentDetailResource extends JsonResource
{
    /**
     * @return array{
     *     ulid: string,
     *     startsAt: string,
     *     endsAt: string,
     *     status: string,
     *     statusLabel: string,
     *     patientFirstName: string,
     *     patientAge: int|null,
     *     serviceName: string,
     *     emergency: bool,
     *     can: array{complete: bool},
     *     followUp: array{statusLabel: string, startsOn: string, endsOn: string, sameDoctor: bool}|null,
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'startsAt' => $this->starts_at->toIso8601ZuluString(),
            'endsAt' => $this->ends_at->toIso8601ZuluString(),
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'patientFirstName' => $this->patient->firstName(),
            'patientAge' => $this->patient->ageInYears(),
            'serviceName' => $this->service->name,
            // A yes/no only, never the words that matched. It explains why the AI summary was skipped.
            'emergency' => $this->emergency_flag,
            // Only for showing the button: the Policy settled who, this is the state (a visit that took place and is
            // not over). CompleteAppointment checks both again on submit.
            'can' => [
                'complete' => $this->status === AppointmentStatus::CheckedIn && Gate::allows('complete', $this->resource),
            ],
            // What the doctor asked for, so the page can say so. The note is left out on purpose: the page does not need it.
            'followUp' => $this->followUp === null ? null : [
                'statusLabel' => $this->followUp->status->label(),
                'startsOn' => $this->followUp->window_starts_on->toDateString(),
                'endsOn' => $this->followUp->window_ends_on->toDateString(),
                'sameDoctor' => $this->followUp->same_doctor,
            ],
        ];
    }
}
