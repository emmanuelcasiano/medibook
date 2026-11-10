<?php

declare(strict_types=1);

use App\Actions\Intake\BuildIntakePrompt;
use App\Actions\Intake\GenerateIntakeSummary;
use App\Actions\Triage\RecordVitals;
use App\Ai\Agents\IntakeSummarizer;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Intake;
use App\Models\IntakeSummary;
use App\Models\VitalsRecord;
use Carbon\CarbonImmutable;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredTextResponse;

// "Send less" (PROJECT.md F6): the AI provider gets the intake answers and nothing else. Vital signs are health data
// that the intake never held, so they must not reach the prompt or the stored summary by any route. These tests have no
// route or Policy of their own (the Action and the job are covered elsewhere), so there is no denied-access or validation
// test here. "Now" is Friday 30 October 2026, 09:00 in Manila.

/** A good model answer. */
function vitalsAiAnswer(): StructuredTextResponse
{
    $fields = [
        'headline' => 'Dry cough for a few days',
        'timeline' => ['Cough at night'],
        'in_english' => null,
        'gaps' => [],
        'check_with_patient' => [],
    ];

    return new StructuredTextResponse($fields, json_encode($fields), new TextUsage(100, 50), new Meta('anthropic', 'test-model'));
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'Asia/Manila'));

    config([
        'medibook.ai.enabled' => true,
        'medibook.ai.provider' => 'anthropic',
        'medibook.ai.model' => 'test-model',
        'medibook.ai.daily_token_cap' => 100000,
    ]);

    $this->appointment = Appointment::factory()->checkedIn()->create();
    $this->intake = Intake::factory()->for($this->appointment)->create();

    // Readings with decimals that can't turn up by chance in a prompt, and a key name that only vitals have.
    $this->readings = ['systolic' => 187, 'diastolic' => 121, 'pulse' => 143, 'temperature' => 39.4, 'oxygen_saturation' => 91, 'weight' => 83.7];
});

it('sends the intake answers and no vital sign, even when the appointment has vitals on record', function () {
    VitalsRecord::factory()->forAppointment($this->appointment)->create(['readings' => $this->readings]);
    IntakeSummarizer::fake([vitalsAiAnswer()])->preventStrayPrompts();

    app(GenerateIntakeSummary::class)->handle($this->intake);

    IntakeSummarizer::assertPromptedTimes(1);
    // The prompt is exactly the answers, as it is for an appointment with no vitals. Nothing was added to it.
    IntakeSummarizer::assertPrompted(fn ($prompt): bool => (string) $prompt->prompt === app(BuildIntakePrompt::class)->handle($this->intake->answers));
    IntakeSummarizer::assertPrompted(fn ($prompt): bool => ! str_contains((string) $prompt->prompt, '39.4')
        && ! str_contains((string) $prompt->prompt, '83.7')
        && ! str_contains((string) $prompt->prompt, 'systolic')
        && ! str_contains(strtolower((string) $prompt->prompt), 'vital'));
});

it('keeps every vital sign out of the stored summary', function () {
    VitalsRecord::factory()->forAppointment($this->appointment)->create(['readings' => $this->readings]);
    IntakeSummarizer::fake([vitalsAiAnswer()]);

    app(GenerateIntakeSummary::class)->handle($this->intake);

    $stored = json_encode(IntakeSummary::query()->sole()->summary, JSON_THROW_ON_ERROR);

    expect($stored)->not->toContain('39.4')->not->toContain('83.7')->not->toContain('systolic');
});

it('does not call the AI at all when the nurse records or corrects vitals', function () {
    IntakeSummarizer::fake()->preventStrayPrompts();

    $nurse = userWithRole(Role::Nurse);
    app(RecordVitals::class)->handle($nurse, $this->appointment, $this->readings);
    app(RecordVitals::class)->handle($nurse, $this->appointment, [...$this->readings, 'pulse' => 90]);

    // No new summary is made from a vitals save: the intake is closed at check-in and vitals are not part of it.
    IntakeSummarizer::assertNeverPrompted();
    expect(IntakeSummary::count())->toBe(0);
});
