<?php

declare(strict_types=1);

namespace App\Http\Resources\Schedule;

use App\Actions\Schedule\WalkInOptions;
use App\Models\Doctor;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The walk-in dialog's choices as the browser receives them: visit types and doctors by id and name, start times as
 * UTC instants. Nothing about any patient. Call `->resolve()` to get the plain array.
 *
 * @mixin WalkInOptions
 */
final class WalkInOptionsResource extends JsonResource
{
    /**
     * @return array{
     *     services: array<int, array{id: int, name: string, durationMinutes: int}>,
     *     doctors: array<int, array{id: int, name: string}>,
     *     slots: array<int, array{startsAt: string}>,
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'services' => $this->services
                ->map(fn (Service $service): array => ['id' => $service->id, 'name' => $service->name, 'durationMinutes' => $service->duration_minutes])
                ->values()
                ->all(),
            'doctors' => $this->doctors
                ->map(fn (Doctor $doctor): array => ['id' => $doctor->id, 'name' => $doctor->user->name])
                ->values()
                ->all(),
            'slots' => $this->slots
                ->map(fn (CarbonImmutable $slot): array => ['startsAt' => $slot->toIso8601ZuluString()])
                ->values()
                ->all(),
        ];
    }
}
