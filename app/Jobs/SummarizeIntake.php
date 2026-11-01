<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Intake\GenerateIntakeSummary;
use App\Enums\SummaryStatus;
use App\Models\Intake;
use App\Models\IntakeSummary;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the AI summary of one intake outside the web request (PROJECT.md F6, ai.md section 3), so a slow or failing
 * provider can never break the intake form. All the rules are in GenerateIntakeSummary. This job only retries it.
 *
 * It waits for the commit (`ShouldQueueAfterCommit`), so a worker never looks for an intake that is not saved yet.
 * `retry_after` in config/queue.php (90 s) stays longer than `$timeout`, so a second worker can't take a job that is still
 * running. `$timeout` needs the `pcntl` extension, which Windows PHP lacks: it only works on the Linux server.
 */
final class SummarizeIntake implements ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> Seconds to wait before retry 2 and retry 3. */
    public array $backoff = [10, 60, 300];

    public int $timeout = 60;

    /**
     * The job holds the id and not the model, so no intake answers are written into the queue table, and a deleted
     * intake does not break the unserialize step.
     */
    public function __construct(public readonly int $intakeId) {}

    /**
     * Only a `pending` row is worked on. That makes the job safe to run twice (a retry after a crash, or two quick
     * edits that queued two jobs): the first run replaces the row, and the second finds it no longer `pending` and stops
     * without a paid call. An emergency edit leaves a `skipped` row, so an old job can't summarize it either.
     */
    public function handle(GenerateIntakeSummary $action): void
    {
        $intake = Intake::query()->find($this->intakeId);

        if ($intake === null) {
            return;
        }

        $stillPending = IntakeSummary::query()
            ->where('intake_id', $intake->id)
            ->where('status', SummaryStatus::Pending)
            ->exists();

        if (! $stillPending) {
            return;
        }

        $action->handle($intake);
    }

    /**
     * Called once, after the last try. The raw answers are untouched, so the doctor still sees them with no summary.
     * Only the class name is saved: the exception message can carry the provider's text or the patient's words.
     */
    public function failed(?Throwable $exception): void
    {
        $error = $exception === null ? null : class_basename($exception);

        IntakeSummary::query()
            ->where('intake_id', $this->intakeId)
            ->where('status', SummaryStatus::Pending)
            ->update(['status' => SummaryStatus::Failed->value, 'error' => $error]);

        Log::warning('Intake summary job failed', ['intake_id' => $this->intakeId, 'error' => $error]);
    }
}
