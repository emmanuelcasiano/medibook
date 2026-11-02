<?php

declare(strict_types=1);

namespace App\Http\Resources\Doctor;

use App\Enums\IntakeDuration;
use App\Http\Resources\Intake\IntakeResource;
use App\Models\Intake;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An intake as the doctor's appointment page receives it: the same answers the patient wrote (listed key by key in
 * `IntakeResource`), plus the "how long" choice in words. Health text: build it only after `IntakePolicy::view` has
 * passed. Call `->resolve()` for the Inertia prop.
 *
 * @mixin Intake
 */
final class IntakeDetailResource extends JsonResource
{
    /**
     * @return array{
     *     answers: array{reason: string, symptoms: string|null, duration: string|null, allergies: string|null, medicines: string|null, fever: bool, pregnant: bool, recent_surgery: bool},
     *     durationLabel: string|null,
     *     submittedAt: string,
     * }
     */
    public function toArray(Request $request): array
    {
        $intake = (new IntakeResource($this->resource))->resolve();

        return [
            'answers' => $intake['answers'],
            // A value that is no longer in the enum (an older row) is shown as "not given", not as an error.
            'durationLabel' => IntakeDuration::tryFrom((string) $intake['answers']['duration'])?->label(),
            'submittedAt' => $intake['submittedAt'],
        ];
    }
}
