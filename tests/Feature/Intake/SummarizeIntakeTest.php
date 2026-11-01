<?php

declare(strict_types=1);

use App\Actions\Intake\GenerateIntakeSummary;
use App\Actions\Intake\SaveIntake;
use App\Ai\Agents\IntakeSummarizer;
use App\Enums\SummaryStatus;
use App\Jobs\SummarizeIntake;
use App\Models\Appointment;
use App\Models\Intake;
use App\Models\IntakeSummary;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredTextResponse;

// The job has no route and no actor: it is queued by SaveIntake, which checks IntakePolicy, so there is no denied-access
// test here (SaveIntakeTest checks that a refused save queues nothing). Tests run the queue as `sync`, so a dispatched
// job runs at once. Every test fakes IntakeSummarizer, so none can reach a real provider. "Now" is Friday 30 October
// 2026, 09:00 in Manila.

/** A good model answer. The chief complaint tells the tests which answers it was made from. */
function jobAiAnswer(string $complaint = 'Dry cough for a few days'): StructuredTextResponse
{
    $fields = [
        'chief_complaint' => $complaint,
        'key_points' => ['Cough at night'],
        'allergies_and_meds' => 'Not given',
        'questions_for_doctor' => [],
    ];

    return new StructuredTextResponse($fields, json_encode($fields), new TextUsage(100, 50), new Meta('anthropic', 'test-model'));
}

/** @return array{reason: string, symptoms: string|null, duration: string|null, allergies: string|null, medicines: string|null, fever: bool, pregnant: bool, recent_surgery: bool} */
function jobIntakeAnswers(string $reason = 'Cough that does not go away'): array
{
    return [
        'reason' => $reason,
        'symptoms' => null,
        'duration' => 'few_days',
        'allergies' => null,
        'medicines' => null,
        'fever' => false,
        'pregnant' => false,
        'recent_surgery' => false,
    ];
}

function runSummarizeJob(Intake $intake): void
{
    $job = new SummarizeIntake($intake->id);

    app()->call($job->handle(...));
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));

    config([
        'medibook.ai.enabled' => true,
        'medibook.ai.provider' => 'anthropic',
        'medibook.ai.model' => 'test-model',
        'medibook.ai.daily_token_cap' => 100000,
    ]);

    $this->patient = User::factory()->patient()->create();
    $this->appointment = Appointment::factory()->for($this->patient, 'patient')->booked()->startingIn(days: 2)->create();
    $this->intake = Intake::factory()->for($this->appointment)->create();
});

// Happy path

it('turns a pending summary into a ready one', function () {
    IntakeSummary::factory()->pending()->for($this->intake)->create();
    IntakeSummarizer::fake([jobAiAnswer()]);

    runSummarizeJob($this->intake);

    $summary = IntakeSummary::query()->sole();

    expect($summary->status)->toBe(SummaryStatus::Ready)
        ->and($summary->summary['chief_complaint'])->toBe('Dry cough for a few days')
        ->and($summary->model)->toBe('test-model')
        ->and($summary->input_tokens)->toBe(100)
        ->and($summary->output_tokens)->toBe(50);
});

it('gives a saved intake a real summary through the queue', function () {
    IntakeSummarizer::fake([jobAiAnswer()]);
    $appointment = Appointment::factory()->for($this->patient, 'patient')->booked()->startingIn(days: 3)->create();

    $intake = app(SaveIntake::class)->handle($this->patient, $appointment, jobIntakeAnswers());

    expect($intake->summary()->sole()->status)->toBe(SummaryStatus::Ready);
    IntakeSummarizer::assertPromptedTimes(1);
});

// Editing

it('makes a new summary from the new answers when the intake is edited', function () {
    IntakeSummarizer::fake([jobAiAnswer('First complaint'), jobAiAnswer('Second complaint')]);
    $appointment = Appointment::factory()->for($this->patient, 'patient')->booked()->startingIn(days: 3)->create();
    $doctor = User::factory()->doctor()->create();

    $intake = app(SaveIntake::class)->handle($this->patient, $appointment, jobIntakeAnswers('First reason'));
    $first = $intake->summary()->sole();
    $first->forceFill(['reviewed_by' => $doctor->id, 'reviewed_at' => now()])->save();

    app(SaveIntake::class)->handle($this->patient, $appointment, jobIntakeAnswers('Second reason'));

    $second = IntakeSummary::query()->sole();

    expect($second->id)->not->toBe($first->id)
        ->and($second->status)->toBe(SummaryStatus::Ready)
        ->and($second->summary['chief_complaint'])->toBe('Second complaint')
        ->and($second->reviewed_by)->toBeNull()
        ->and($second->reviewed_at)->toBeNull();

    IntakeSummarizer::assertPromptedTimes(2);
    IntakeSummarizer::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, 'Second reason'));
});

// Failure

// The sync queue calls failed() after the exception, like a worker does after the last try.
it('marks the summary failed, with the error class only, and keeps the raw answers', function () {
    IntakeSummary::factory()->pending()->for($this->intake)->create();
    $answersBefore = $this->intake->answers;
    IntakeSummarizer::fake(fn () => throw new RuntimeException('provider said: patient has chest rash'));

    expect(fn () => SummarizeIntake::dispatch($this->intake->id))->toThrow(RuntimeException::class);

    $summary = IntakeSummary::query()->sole();

    expect($summary->status)->toBe(SummaryStatus::Failed)
        ->and($summary->error)->toBe('RuntimeException')
        ->and($summary->summary)->toBeNull()
        ->and($this->intake->fresh()->answers)->toBe($answersBefore);
});

it('keeps the summary pending when a provider error will be tried again', function () {
    IntakeSummary::factory()->pending()->for($this->intake)->create();
    IntakeSummarizer::fake(fn () => throw new RuntimeException('timeout'));

    expect(fn () => runSummarizeJob($this->intake))->toThrow(RuntimeException::class);

    expect(IntakeSummary::query()->sole()->status)->toBe(SummaryStatus::Pending);
});

it('does not touch a summary that is no longer pending when the job fails', function () {
    $ready = IntakeSummary::factory()->for($this->intake)->create();

    (new SummarizeIntake($this->intake->id))->failed(new RuntimeException('late failure'));

    expect($ready->fresh()->status)->toBe(SummaryStatus::Ready)
        ->and($ready->fresh()->error)->toBeNull();
});

// Skips

it('is safe to run twice and prompts once', function () {
    IntakeSummary::factory()->pending()->for($this->intake)->create();
    IntakeSummarizer::fake([jobAiAnswer()])->preventStrayPrompts();

    runSummarizeJob($this->intake);
    runSummarizeJob($this->intake);

    IntakeSummarizer::assertPromptedTimes(1);
    expect(IntakeSummary::query()->sole()->status)->toBe(SummaryStatus::Ready);
});

it('does not prompt when the row is not pending', function (string $state) {
    $row = IntakeSummary::factory()->for($this->intake);
    $row = $state === 'none' ? null : $row->{$state}();
    $row?->create();
    IntakeSummarizer::fake()->preventStrayPrompts();

    runSummarizeJob($this->intake);

    IntakeSummarizer::assertNeverPrompted();
    expect(IntakeSummary::query()->count())->toBe($state === 'none' ? 0 : 1);
})->with(['none', 'skipped', 'failed']);

it('ends quietly when the intake no longer exists', function () {
    IntakeSummarizer::fake()->preventStrayPrompts();

    (new SummarizeIntake(999999))->handle(app(GenerateIntakeSummary::class));

    IntakeSummarizer::assertNeverPrompted();
});

it('ends with a skipped summary and no prompt when the AI is off', function () {
    config(['medibook.ai.enabled' => false]);
    IntakeSummarizer::fake()->preventStrayPrompts();
    $appointment = Appointment::factory()->for($this->patient, 'patient')->booked()->startingIn(days: 3)->create();

    $intake = app(SaveIntake::class)->handle($this->patient, $appointment, jobIntakeAnswers());

    IntakeSummarizer::assertNeverPrompted();
    expect($intake->summary()->sole()->status)->toBe(SummaryStatus::Skipped);
});

// Retry settings. A worker reads these properties, and no request can reach them, so the values are checked directly.
it('retries three times with a longer wait each time and stops a stuck call', function () {
    $job = new SummarizeIntake($this->intake->id);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 60, 300])
        ->and($job->timeout)->toBe(60)
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'));
});
