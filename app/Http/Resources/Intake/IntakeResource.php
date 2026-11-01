<?php

declare(strict_types=1);

namespace App\Http\Resources\Intake;

use App\Models\Intake;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An intake as the patient's own form page receives it. The keys are listed one by one, so a stray key in the stored
 * answers can never reach the browser, and a missing key (an older row) becomes an empty answer instead of an error.
 * Health text: only send it to a page the Policy has already opened. Call `->resolve()` for the Inertia prop.
 *
 * @mixin Intake
 */
final class IntakeResource extends JsonResource
{
    /**
     * @return array{
     *     answers: array{reason: string, symptoms: string|null, duration: string|null, allergies: string|null, medicines: string|null, fever: bool, pregnant: bool, recent_surgery: bool},
     *     submittedAt: string,
     * }
     */
    public function toArray(Request $request): array
    {
        $answers = $this->answers;

        return [
            'answers' => [
                'reason' => (string) ($answers['reason'] ?? ''),
                'symptoms' => $answers['symptoms'] ?? null,
                'duration' => $answers['duration'] ?? null,
                'allergies' => $answers['allergies'] ?? null,
                'medicines' => $answers['medicines'] ?? null,
                'fever' => (bool) ($answers['fever'] ?? false),
                'pregnant' => (bool) ($answers['pregnant'] ?? false),
                'recent_surgery' => (bool) ($answers['recent_surgery'] ?? false),
            ],
            'submittedAt' => $this->submitted_at->toIso8601ZuluString(),
        ];
    }
}
