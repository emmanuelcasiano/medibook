<?php

declare(strict_types=1);

namespace App\Http\Resources\Schedule;

use App\Actions\Schedule\MoveOptions;
use App\Models\Doctor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The staff Move picker as the page receives it: a doctor's id and name, and start times as UTC instants. Nothing
 * about the patient. Call `->resolve()` to get the plain array for an Inertia prop.
 *
 * @mixin MoveOptions
 */
final class MoveOptionsResource extends JsonResource
{
    /**
     * @return array{
     *     doctors: array<int, array{id: int, name: string}>,
     *     slots: array<int, array{startsAt: string}>,
     * }
     */
    public function toArray(Request $request): array
    {
        return [
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
