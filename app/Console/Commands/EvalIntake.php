<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Intake\EstimateAiCost;
use App\Actions\Intake\EvaluateIntakeCase;
use App\Ai\Evals\IntakeEvalFixtures;
use App\Ai\Evals\IntakeEvalResult;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use JsonException;
use UnexpectedValueException;

#[Signature('medibook:eval-intake {--case= : Run only the case with this id}')]
#[Description('Run the intake eval cases against the real AI model and print a score (costs money, run by hand)')]
final class EvalIntake extends Command
{
    public function handle(IntakeEvalFixtures $fixtures, EvaluateIntakeCase $evaluate, EstimateAiCost $estimateCost): int
    {
        // Every refusal comes before the first prompt, so a wrong setup never costs a token.
        $problem = $this->setupProblem();

        if ($problem !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $cases = $this->loadCases($fixtures);

        if ($cases === null) {
            return self::FAILURE;
        }

        $results = [];

        foreach ($cases as $case) {
            $result = $evaluate->handle($case);
            $results[] = $result;

            $this->line(sprintf('  %s %s', $result->passed() ? 'PASS' : 'FAIL', $result->id));
        }

        $this->report($results, $estimateCost);

        return collect($results)->contains(fn (IntakeEvalResult $result): bool => $result->mustHold() && ! $result->passed())
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * Why the eval can't run, or null when it can. The key is only checked for being set, never printed.
     */
    private function setupProblem(): ?string
    {
        if (! config('medibook.ai.enabled')) {
            return 'The AI is off. Set MEDIBOOK_AI_ENABLED=true in .env to run the evals.';
        }

        $provider = (string) config('medibook.ai.provider');
        $model = config('medibook.ai.model');

        if (! is_string($model) || $model === '') {
            return 'No model is set. Set MEDIBOOK_AI_MODEL in .env.';
        }

        if (! filled(config("ai.providers.{$provider}.key"))) {
            return "No API key is set for the \"{$provider}\" provider. Put it in .env (see config/ai.php).";
        }

        return null;
    }

    /**
     * The checked cases in file name order, or null after printing why they can't be used. A bad file stops the whole
     * run: a score from half the cases would mean nothing.
     *
     * @return list<array<string, mixed>>|null
     */
    private function loadCases(IntakeEvalFixtures $fixtures): ?array
    {
        try {
            $all = $fixtures->all();
        } catch (JsonException|UnexpectedValueException $e) {
            $this->error('A fixture file can\'t be read: '.$e->getMessage());

            return null;
        }

        $only = $this->option('case');

        if (is_string($only) && $only !== '') {
            if (! isset($all[$only])) {
                $this->error("There is no case named \"{$only}\".");

                return null;
            }

            $all = [$only => $all[$only]];
        }

        if ($all === []) {
            $this->error('There are no fixtures to run.');

            return null;
        }

        $cases = [];

        foreach ($all as $name => $fixture) {
            try {
                $cases[] = $fixtures->validate($fixture);
            } catch (ValidationException $e) {
                $this->error("{$name} has a bad shape:");

                foreach ($e->errors() as $field => $messages) {
                    $this->line("  {$field}: ".implode(' ', $messages));
                }

                return null;
            }
        }

        return $cases;
    }

    /**
     * @param  list<IntakeEvalResult>  $results
     */
    private function report(array $results, EstimateAiCost $estimateCost): void
    {
        $this->newLine();

        $this->table(
            ['Case', 'Category', 'Result', 'Tokens in/out', 'Failed checks'],
            array_map(fn (IntakeEvalResult $result): array => [
                $result->id,
                $result->category,
                $result->passed() ? 'pass' : 'FAIL',
                $result->isScored() ? "{$result->inputTokens}/{$result->outputTokens}" : 'no call',
                implode('; ', $result->failures),
            ], $results),
        );

        $scored = array_filter($results, fn (IntakeEvalResult $result): bool => $result->isScored());

        $this->line(sprintf('Score: %d/%d', $this->passedCount($scored), count($scored)));

        foreach (['injection' => 'Injection held', 'diagnosis' => 'Diagnosis held', 'emergency' => 'Emergency flagged'] as $category => $label) {
            $inCategory = array_filter($results, fn (IntakeEvalResult $result): bool => $result->category === $category);

            if ($inCategory !== []) {
                $this->line(sprintf('%s: %d/%d', $label, $this->passedCount($inCategory), count($inCategory)));
            }
        }

        $input = array_sum(array_map(fn (IntakeEvalResult $result): int => $result->inputTokens, $results));
        $output = array_sum(array_map(fn (IntakeEvalResult $result): int => $result->outputTokens, $results));
        $cost = $estimateCost->handle($input, $output);

        $this->line(sprintf(
            'Tokens: %d in, %d out. Estimated cost: %s',
            $input,
            $output,
            $cost === null ? 'price not set' : "\${$cost}",
        ));
    }

    /**
     * @param  array<array-key, IntakeEvalResult>  $results
     */
    private function passedCount(array $results): int
    {
        return count(array_filter($results, fn (IntakeEvalResult $result): bool => $result->passed()));
    }
}
