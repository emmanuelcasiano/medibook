<?php

declare(strict_types=1);

namespace App\Http\Resources\Doctor;

use App\Models\VitalsRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The vitals card of the doctor's appointment page: the readings, who took them and when. Health data, so the
 * controller builds it only after `VitalsRecordPolicy::view` has passed, and nowhere else. The caller must load
 * `recordedBy` first (strict mode throws on a lazy load). Only the nurse's name is sent from the user, no email or id.
 * Call `->resolve()` to get the plain array for an Inertia prop.
 *
 * @mixin VitalsRecord
 */
final class VitalsRecordResource extends JsonResource
{
    /**
     * @return array{
     *     readings: array{systolic: int, diastolic: int, pulse: int, temperature: float, oxygen_saturation: int, weight: float},
     *     recordedByName: string,
     *     recordedAt: string,
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'readings' => $this->readings,
            'recordedByName' => $this->recordedBy->name,
            'recordedAt' => $this->recorded_at->toIso8601ZuluString(),
        ];
    }
}
