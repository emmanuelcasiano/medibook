<?php

declare(strict_types=1);

namespace App\Http\Resources\Staff;

use App\Actions\Staff\FollowUpBookingOptions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The staff "Book for patient" picker as the page receives it: the days with how many free times each has, the chosen
 * day and its start times as UTC instants. Nothing about the patient. Call `->resolve()` to get the plain array for an
 * Inertia prop.
 *
 * @mixin FollowUpBookingOptions
 */
final class FollowUpBookingOptionsResource extends JsonResource
{
    /**
     * @return array{
     *     days: list<array{date: string, slotsCount: int}>,
     *     selectedDate: string|null,
     *     slots: list<array{startsAt: string}>,
     *     unavailable: string|null,
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'days' => array_map(
                fn (string $date, int $count): array => ['date' => $date, 'slotsCount' => $count],
                array_keys($this->days),
                $this->days,
            ),
            'selectedDate' => $this->selectedDate,
            'slots' => array_map(
                fn (CarbonImmutable $slot): array => ['startsAt' => $slot->toIso8601ZuluString()],
                $this->slots,
            ),
            'unavailable' => $this->unavailable,
        ];
    }
}
