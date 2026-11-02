<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Enums\SummaryStatus;
use App\Models\Appointment;
use App\Models\IntakeSummary;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class MarkSummaryReviewed
{
    /**
     * The doctor confirms they have read the AI draft: `reviewed_by` and `reviewed_at` are saved on the summary
     * (PROJECT.md F6). Both come from here, the signed-in doctor and the server clock, never from the request.
     *
     * Only a `ready` summary has a draft to review. $version is the token of the draft that was on the doctor's page
     * (`IntakeSummary::version()`). A patient can edit the intake until check-in, which replaces the draft, so a page
     * left open may show an old one. If the token no longer matches, the review is refused and nothing is saved:
     * "reviewed" must never be stamped on a draft the doctor has not read.
     *
     * Marking a summary that is already reviewed changes nothing, so a double click or a second tab keeps the first
     * who and when.
     *
     * This Action checks the Policies itself (architecture.md section 3), so a caller that skips the controller can't
     * skip the check either. The appointment is checked first, so a user who may not open it learns nothing about
     * its intake. Then the intake Policy, because the draft is health data (staff and admins never get it).
     *
     * @throws AuthorizationException When $actor may not review this appointment's summary (only its doctor may).
     * @throws ValidationException When there is no ready summary to review, or the draft changed since the doctor read it.
     */
    public function handle(User $actor, Appointment $appointment, string $version): IntakeSummary
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('view', $appointment);

        $intake = $appointment->loadMissing('intake')->intake;

        if ($intake === null) {
            throw ValidationException::withMessages(['summary' => 'There is no summary to review.']);
        }

        // Give the Policy the appointment we already have, so it does not load its own copy.
        $intake->setRelation('appointment', $appointment);
        $gate->authorize('reviewSummary', $intake);

        return DB::transaction(function () use ($actor, $intake, $version): IntakeSummary {
            // Lock and re-read by intake. Editing the intake replaces the draft, so the model in the caller's hand may
            // be an old one. Under the lock the draft can't change between the token check and the write below.
            $summary = IntakeSummary::query()->where('intake_id', $intake->id)->lockForUpdate()->first();

            if ($summary === null || $summary->status !== SummaryStatus::Ready) {
                throw ValidationException::withMessages(['summary' => 'Only a ready summary can be reviewed.']);
            }

            // Constant-time compare, as for any token. The token is not a secret, but there is no reason to leak timing.
            if (! hash_equals($summary->version(), $version)) {
                throw ValidationException::withMessages(['summary' => 'The summary changed while you were reading. The page now shows the new draft. Read it, then mark it reviewed.']);
            }

            if ($summary->reviewed_at === null) {
                // Not fillable on purpose (see the model): only this Action may set who reviewed and when.
                $summary->forceFill([
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => CarbonImmutable::now(),
                ])->save();
            }

            return $summary;
        });
    }
}
