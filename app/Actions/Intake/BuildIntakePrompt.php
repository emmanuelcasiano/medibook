<?php

declare(strict_types=1);

namespace App\Actions\Intake;

final class BuildIntakePrompt
{
    /**
     * The prompt is the answers and nothing else. json_encode with JSON_HEX_TAG turns "<" and ">" into escapes, so a
     * patient can't type "</intake>" to close the tag early and put text outside the data block.
     *
     * One class builds it for both the real summary (`GenerateIntakeSummary`) and the evals (`EvaluateIntakeCase`), so
     * an eval always tests the prompt the app really sends.
     *
     * @param  array<string, mixed>  $answers
     */
    public function handle(array $answers): string
    {
        $json = json_encode(
            $answers,
            JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return "<intake>\n{$json}\n</intake>";
    }
}
