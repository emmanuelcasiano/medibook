<?php

declare(strict_types=1);

namespace App\Actions\Intake;

use App\Enums\SummaryGap;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ValidateIntakeSummary
{
    /**
     * Structured output helps, but the shape and the lengths are still checked before anything is saved. Only the five
     * known keys come back, so a stray or old (v1) field from the model can't reach the database. `gaps` can only hold
     * the fixed keys of `SummaryGap`, so the model can't smuggle free text through it.
     *
     * Shared by `GenerateIntakeSummary` and the evals, so an eval fails on exactly the answers the app would refuse.
     *
     * @param  array<string, mixed>  $output
     * @return array{headline: string, timeline: list<string>, in_english: string|null, gaps: list<string>, check_with_patient: list<string>}
     *
     * @throws ValidationException
     */
    public function handle(array $output): array
    {
        // `present`, not `required`: an empty list or a null `in_english` is a real answer, and `required` refuses it.
        $valid = Validator::make($output, [
            'headline' => ['required', 'string', 'max:160'],
            'timeline' => ['present', 'array', 'max:8'],
            'timeline.*' => ['string', 'max:200'],
            'in_english' => ['present', 'nullable', 'string', 'max:1000'],
            'gaps' => ['present', 'array', 'max:'.count(SummaryGap::cases())],
            'gaps.*' => ['string', 'distinct', Rule::enum(SummaryGap::class)],
            'check_with_patient' => ['present', 'array', 'max:5'],
            'check_with_patient.*' => ['string', 'max:200'],
        ])->validate();

        // Built key by key, so the saved shape and its order never depend on the validator's output order.
        return [
            'headline' => $valid['headline'],
            'timeline' => array_values($valid['timeline']),
            // Laravel skips the string rules for "", so a blank answer is turned into null here: "no translation".
            'in_english' => is_string($valid['in_english']) && trim($valid['in_english']) !== '' ? $valid['in_english'] : null,
            'gaps' => array_values($valid['gaps']),
            'check_with_patient' => array_values($valid['check_with_patient']),
        ];
    }
}
