<?php

declare(strict_types=1);

namespace App\Actions\Intake;

use App\Ai\Agents\IntakeSummarizer;
use App\Enums\SummaryStatus;
use App\Models\Intake;
use App\Models\IntakeSummary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class GenerateIntakeSummary
{
    public function __construct(
        private readonly GetTokensUsedToday $tokensUsedToday,
        private readonly BuildIntakePrompt $buildPrompt,
        private readonly ValidateIntakeSummary $validateSummary,
    ) {}

    /**
     * Make the AI draft summary of one intake (PROJECT.md F6) and save it in `intake_summaries`.
     *
     * Every safety check is plain PHP and runs before the model is called (ai.md section 3): an emergency intake, the AI
     * switched off, and the daily token cap all end in a `skipped` row and no prompt. Only the intake answers are sent,
     * as data inside `<intake>`: no name, email, phone, birth date or appointment details.
     *
     * The result is checked before it is saved. A broken answer becomes a `failed` row, with the tokens it cost, and is
     * not retried. A provider error (timeout, rate limit) is not caught here: it goes up to the `SummarizeIntake` job,
     * which retries it and sets `failed` after the last try.
     *
     * Each run replaces the old summary row with a new one. That drops the old "reviewed" mark (the summary is about
     * older answers) and gives the row today's `created_at`, which the daily cap counts.
     */
    public function handle(Intake $intake): IntakeSummary
    {
        $intake->loadMissing('appointment');

        // Second guard: SaveIntake already wrote `skipped` for an emergency, but this Action must not trust its caller.
        if ($intake->appointment->emergency_flag) {
            return $this->replace($intake, SummaryStatus::Skipped);
        }

        if (! config('medibook.ai.enabled')) {
            return $this->replace($intake, SummaryStatus::Skipped);
        }

        if ($this->tokensUsedToday->handle() >= (int) config('medibook.ai.daily_token_cap')) {
            return $this->replace($intake, SummaryStatus::Skipped);
        }

        $model = config('medibook.ai.model');

        $response = (new IntakeSummarizer)->prompt(
            $this->buildPrompt->handle($intake->answers),
            provider: (string) config('medibook.ai.provider'),
            model: is_string($model) && $model !== '' ? $model : null,
        );

        $usage = [
            'model' => $response->meta->model ?? (is_string($model) ? $model : null),
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
        ];

        try {
            $summary = $this->validateSummary->handle($response->toArray());
        } catch (ValidationException $e) {
            // Class name only: the message and the model output can carry the patient's words.
            return $this->replace($intake, SummaryStatus::Failed, [...$usage, 'error' => class_basename($e)]);
        }

        return $this->replace($intake, SummaryStatus::Ready, [...$usage, 'summary' => $summary]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function replace(Intake $intake, SummaryStatus $status, array $attributes = []): IntakeSummary
    {
        $summary = DB::transaction(function () use ($intake, $status, $attributes): IntakeSummary {
            IntakeSummary::query()->where('intake_id', $intake->id)->delete();

            return IntakeSummary::query()->create([
                'intake_id' => $intake->id,
                'status' => $status,
                ...$attributes,
            ]);
        });

        // IDs, status and counts only. Never the prompt, the answers or the model's text (security.md section 8).
        Log::info('Intake summary saved', [
            'intake_id' => $intake->id,
            'status' => $status->value,
            'model' => $summary->model,
            'input_tokens' => $summary->input_tokens,
            'output_tokens' => $summary->output_tokens,
            'error' => $summary->error,
        ]);

        return $summary;
    }
}
