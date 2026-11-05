<?php

declare(strict_types=1);

namespace App\Actions\Intake;

use App\Enums\SummaryStatus;
use App\Jobs\SummarizeIntake;
use App\Models\Appointment;
use App\Models\Intake;
use App\Models\IntakeSummary;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class SaveIntake
{
    public function __construct(
        private readonly DetectEmergency $detectEmergency,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Save the intake form of an appointment: the first save creates the intake, every later save replaces its
     * answers. There is one intake per appointment (a unique index), so a patient never gets a second row.
     *
     * The answers are stored with `encrypted:array` (see the Intake model). Only the keys of `$answers` are stored, so
     * a stray field in a request can't reach the database. `submitted_at` is the time of the last save.
     *
     * Every save also runs the emergency check (PROJECT.md F5), in PHP and before any AI. The appointment's
     * `emergency_flag` follows the latest answers: an emergency phrase sets it and skips the AI summary, and an edit
     * without one clears it. The flag, the summary row and the answers change in one transaction, so they can't
     * disagree. The AI summary itself is made later by the `SummarizeIntake` job, queued here after the commit.
     *
     * This Action checks the Policy itself (architecture.md section 3), so a caller that skips the controller can't
     * skip the check either.
     *
     * @param  array{reason: string, symptoms: string|null, duration: string|null, allergies: string|null, medicines: string|null, fever: bool, pregnant: bool, recent_surgery: bool}  $answers
     *
     * @throws AuthorizationException When $actor may not fill in or edit this intake (only the appointment's patient, and only until check-in).
     */
    public function handle(User $actor, Appointment $appointment, array $answers): Intake
    {
        return DB::transaction(function () use ($actor, $appointment, $answers): Intake {
            // Lock and re-read the appointment first. Check-in locks the same row, so a save and a check-in running
            // together can't both pass the "until check-in" rule below. The model in the caller's hand may be stale.
            $current = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            $intake = Intake::query()->where('appointment_id', $current->id)->first();
            $created = $intake === null;
            $wasFlagged = $current->emergency_flag;

            if ($intake === null) {
                Gate::forUser($actor)->authorize('create', [Intake::class, $current]);

                $intake = new Intake(['appointment_id' => $current->id]);
            } else {
                // Hand the policy the locked row, so it judges the status as it is now and does not load its own copy.
                $intake->setRelation('appointment', $current);

                Gate::forUser($actor)->authorize('update', $intake);
            }

            $intake->fill([
                'answers' => [
                    'reason' => $answers['reason'],
                    'symptoms' => $answers['symptoms'],
                    'duration' => $answers['duration'],
                    'allergies' => $answers['allergies'],
                    'medicines' => $answers['medicines'],
                    'fever' => $answers['fever'],
                    'pregnant' => $answers['pregnant'],
                    'recent_surgery' => $answers['recent_surgery'],
                ],
                'submitted_at' => now(),
            ])->save();

            $isEmergency = $this->applyEmergencyCheck($current, $intake);

            // Facts only: that the intake was saved, and whether the staff flag changed. Never the answers or the
            // words that matched. Edits can clear the flag, so the log is the only place that shows it was raised.
            $this->audit->record($actor, 'intake.saved', $intake, [
                'appointment_id' => $current->id,
                'created' => $created,
                'emergency_flag_from' => $wasFlagged,
                'emergency_flag_to' => $isEmergency,
            ]);

            return $intake;
        });
    }

    /**
     * Set the flag and the summary row from the answers just saved. The caller holds the appointment row lock.
     *
     * Every save replaces the old summary row, because an older summary describes older answers:
     * - An emergency gets an empty `skipped` row and no job, so the AI never sees the answers.
     * - Any other save gets an empty `pending` row and a `SummarizeIntake` job. The job is queued after the commit, so
     *   the worker can't run before the new answers are saved. If the AI is off or the daily cap is reached, the job
     *   turns the row into `skipped` (see GenerateIntakeSummary).
     *
     * @return bool Whether the answers were an emergency.
     */
    private function applyEmergencyCheck(Appointment $appointment, Intake $intake): bool
    {
        $answers = $intake->answers;

        $isEmergency = $this->detectEmergency->handle([
            $answers['reason'],
            $answers['symptoms'],
            $answers['allergies'],
            $answers['medicines'],
        ]);

        $appointment->update(['emergency_flag' => $isEmergency]);

        if ($isEmergency) {
            $this->replaceSummary($intake, SummaryStatus::Skipped);

            return true;
        }

        $this->replaceSummary($intake, SummaryStatus::Pending);

        SummarizeIntake::dispatch($intake->id);

        return false;
    }

    private function replaceSummary(Intake $intake, SummaryStatus $status): void
    {
        $summary = IntakeSummary::query()->updateOrCreate(
            ['intake_id' => $intake->id],
            ['status' => $status, 'summary' => null, 'model' => null, 'input_tokens' => null, 'output_tokens' => null, 'error' => null],
        );

        // The review columns are guarded (only MarkSummaryReviewed sets them), so a replaced summary is un-reviewed here.
        $summary->forceFill(['reviewed_by' => null, 'reviewed_at' => null])->save();
    }
}
