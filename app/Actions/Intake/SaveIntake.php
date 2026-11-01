<?php

declare(strict_types=1);

namespace App\Actions\Intake;

use App\Models\Appointment;
use App\Models\Intake;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class SaveIntake
{
    /**
     * Save the intake form of an appointment: the first save creates the intake, every later save replaces its
     * answers. There is one intake per appointment (a unique index), so a patient never gets a second row.
     *
     * The answers are stored with `encrypted:array` (see the Intake model). Only the keys of `$answers` are stored, so
     * a stray field in a request can't reach the database. `submitted_at` is the time of the last save.
     *
     * This Action checks the Policy itself (architecture.md section 3), so a caller that skips the controller can't
     * skip the check either. The emergency check (step 8.3) and the AI summary (Day 9) are not part of it yet.
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

            return $intake;
        });
    }
}
