<?php

declare(strict_types=1);

namespace App\Http\Resources\Doctor;

use App\Enums\SummaryGap;
use App\Enums\SummaryStatus;
use App\Models\IntakeSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The AI draft as the doctor's appointment page receives it. The text is only there for a `ready` summary: a
 * pending, failed or skipped row has none. The keys are listed one by one, so a stray key in the stored JSON can't
 * reach the browser. `error` and the token counts stay on the server. AI text: the page shows it as plain text only.
 * Build it only after `IntakePolicy::viewSummary` has passed. Call `->resolve()` for the Inertia prop.
 *
 * @mixin IntakeSummary
 */
final class IntakeSummaryResource extends JsonResource
{
    /**
     * @return array{
     *     status: string,
     *     statusLabel: string,
     *     summary: array{headline: string, timeline: list<string>, inEnglish: string|null, gaps: list<array{key: string, label: string}>, checkWithPatient: list<string>}|null,
     *     model: string|null,
     *     reviewedAt: string|null,
     *     version: string,
     *     can: array{review: bool},
     * }
     */
    public function toArray(Request $request): array
    {
        $summary = $this->summary;

        return [
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'summary' => $summary === null ? null : [
                // GenerateIntakeSummary validates this shape before it saves, so every key is there.
                'headline' => $summary['headline'],
                'timeline' => $summary['timeline'],
                'inEnglish' => $summary['in_english'],
                // The page shows a fixed label per key, never the model's own words (see SummaryGap). A stored key that
                // is no longer a case (renamed or removed later) is left out, so an old draft can't break the page.
                'gaps' => array_values(collect($summary['gaps'])
                    ->map(static fn (string $key): ?SummaryGap => SummaryGap::tryFrom($key))
                    ->filter()
                    ->map(static fn (SummaryGap $gap): array => ['key' => $gap->value, 'label' => $gap->label()])
                    ->all()),
                'checkWithPatient' => $summary['check_with_patient'],
            ],
            'model' => $this->model,
            'reviewedAt' => $this->reviewed_at?->toIso8601ZuluString(),
            // Sent back by "Mark reviewed", so the review is of the draft the doctor read (see IntakeSummary::version()).
            'version' => $this->version(),
            // Only for showing the button. The resource is built after `viewSummary` has passed, so the role part is
            // settled; what is left is the state. MarkSummaryReviewed checks both again on submit.
            'can' => ['review' => $this->status === SummaryStatus::Ready && $this->reviewed_at === null],
        ];
    }
}
