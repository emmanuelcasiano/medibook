<?php

declare(strict_types=1);

namespace App\Http\Resources\Nurse;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The appointment on the nurse's vitals form: enough to confirm it is the right patient, and nothing else. The caller
 * must load `patient`, `doctor.user` and `service` first (strict mode throws on a lazy load). No intake, emergency
 * flag, birth date or note. Call `->resolve()` to get the plain array for an Inertia prop.
 *
 * @mixin Appointment
 */
final class VitalsFormResource extends JsonResource
{
    /**
     * @return array{ulid: string, startsAt: string, patientName: string, doctorName: string, serviceName: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'startsAt' => $this->starts_at->toIso8601ZuluString(),
            'patientName' => $this->patient->name,
            'doctorName' => $this->doctor->user->name,
            'serviceName' => $this->service->name,
        ];
    }
}
