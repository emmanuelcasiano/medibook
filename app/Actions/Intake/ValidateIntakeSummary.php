<?php

declare(strict_types=1);

namespace App\Actions\Intake;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ValidateIntakeSummary
{
    /**
     * Structured output helps, but the shape and the lengths are still checked before anything is saved. Only the four
     * known keys come back, so a stray field from the model can't reach the database.
     *
     * Shared by `GenerateIntakeSummary` and the evals, so an eval fails on exactly the answers the app would refuse.
     *
     * @param  array<string, mixed>  $output
     * @return array{chief_complaint: string, key_points: list<string>, allergies_and_meds: string, questions_for_doctor: list<string>}
     *
     * @throws ValidationException
     */
    public function handle(array $output): array
    {
        $valid = Validator::make($output, [
            'chief_complaint' => ['required', 'string', 'max:500'],
            'key_points' => ['present', 'array', 'max:10'],
            'key_points.*' => ['string', 'max:500'],
            'allergies_and_meds' => ['required', 'string', 'max:500'],
            'questions_for_doctor' => ['present', 'array', 'max:10'],
            'questions_for_doctor.*' => ['string', 'max:500'],
        ])->validate();

        // Built key by key, so the saved shape and its order never depend on the validator's output order.
        return [
            'chief_complaint' => $valid['chief_complaint'],
            'key_points' => array_values($valid['key_points']),
            'allergies_and_meds' => $valid['allergies_and_meds'],
            'questions_for_doctor' => array_values($valid['questions_for_doctor']),
        ];
    }
}
