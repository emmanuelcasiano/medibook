<?php

declare(strict_types=1);

namespace App\Actions\Intake;

use App\Ai\Agents\IntakeSummarizer;
use App\Ai\Evals\IntakeEvalResult;
use App\Ai\Evals\IntakeSummaryChecks;
use Illuminate\Validation\ValidationException;
use Throwable;

final class EvaluateIntakeCase
{
    public function __construct(
        private readonly DetectEmergency $detectEmergency,
        private readonly BuildIntakePrompt $buildPrompt,
        private readonly ValidateIntakeSummary $validateSummary,
        private readonly IntakeSummaryChecks $checks,
    ) {}

    /**
     * Run one eval fixture (ai.md section 7) and say if it passed.
     *
     * An `emergency` case never reaches the model: it passes only if `DetectEmergency` flags it, the same split the app
     * makes in `SaveIntake`. Any other case goes through the same agent, provider, model, prompt and output rules as a
     * real summary, so the score describes what the app really does. Nothing is saved and the daily token cap is not
     * used: this is a developer's check, not a patient's summary.
     *
     * One bad case must not waste the rest of a paid run. A broken model answer or a provider error becomes a failed
     * case, named by class only because the message can carry the model's text.
     *
     * @param  array<string, mixed>  $fixture  A case that passed `IntakeEvalFixtures::validate()`.
     */
    public function handle(array $fixture): IntakeEvalResult
    {
        /** @var string $id */
        $id = $fixture['id'];
        /** @var string $category */
        $category = $fixture['category'];
        /** @var array<string, mixed> $answers */
        $answers = $fixture['answers'];

        if ($category === 'emergency') {
            $flagged = $this->detectEmergency->handle([
                $answers['reason'], $answers['symptoms'], $answers['allergies'], $answers['medicines'],
            ]);

            return new IntakeEvalResult($id, $category, $flagged ? [] : ['the emergency check did not flag this case']);
        }

        $model = config('medibook.ai.model');

        try {
            $response = (new IntakeSummarizer)->prompt(
                $this->buildPrompt->handle($answers),
                provider: (string) config('medibook.ai.provider'),
                model: is_string($model) && $model !== '' ? $model : null,
            );
        } catch (Throwable $e) {
            return new IntakeEvalResult($id, $category, ['provider error: '.class_basename($e)]);
        }

        $inputTokens = $response->usage->inputTokens;
        $outputTokens = $response->usage->outputTokens;

        try {
            $summary = $this->validateSummary->handle($response->toArray());
        } catch (ValidationException) {
            return new IntakeEvalResult($id, $category, ['the answer is not a valid summary'], $inputTokens, $outputTokens);
        }

        return new IntakeEvalResult($id, $category, $this->checks->failures($fixture, $summary), $inputTokens, $outputTokens);
    }
}
