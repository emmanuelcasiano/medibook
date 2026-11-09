<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\FollowUpEventType;
use App\Enums\FollowUpStatus;
use App\Models\FollowUp;
use App\Models\FollowUpEvent;
use App\Models\User;
use App\Notifications\FollowUpReminder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SendFollowUpReminder
{
    /**
     * Email the patient a reminder to book $followUp (PROJECT.md section 9). The email holds the doctor, the visit
     * type and the dates, and never the doctor's note: it leaves our servers (PROJECT.md F9). It is queued after the
     * commit, so a refused reminder sends nothing.
     *
     * Refused when the follow-up is not `open`, when its window is over (the patient can't book it any more, so the
     * email would point at nothing: staff call instead), and when a reminder already went out today (clinic day), so
     * a double click or two staff members can't send two emails. Locked and read again, like the other staff actions.
     *
     * @throws AuthorizationException When $actor may not manage this follow-up.
     * @throws ValidationException When one of the refusals above applies.
     */
    public function handle(User $actor, FollowUp $followUp): FollowUpEvent
    {
        Gate::forUser($actor)->authorize('manage', $followUp);

        return DB::transaction(function () use ($actor, $followUp): FollowUpEvent {
            $current = FollowUp::query()->whereKey($followUp->id)->lockForUpdate()->firstOrFail();

            if ($current->status !== FollowUpStatus::Open) {
                throw ValidationException::withMessages(['follow_up' => 'This follow-up is not open any more.']);
            }

            $now = CarbonImmutable::now();
            $today = $now->setTimezone((string) config('app.clinic_timezone'))->toDateString();

            if ($current->window_ends_on->toDateString() < $today) {
                throw ValidationException::withMessages(['follow_up' => 'The dates have passed, so a reminder would not help. Call the patient instead.']);
            }

            if ($current->last_reminded_at?->setTimezone((string) config('app.clinic_timezone'))->toDateString() === $today) {
                throw ValidationException::withMessages(['follow_up' => 'A reminder was already sent today.']);
            }

            $current->update(['last_reminded_at' => $now]);

            $event = FollowUpEvent::create([
                'follow_up_id' => $current->id,
                'actor_id' => $actor->id,
                'type' => FollowUpEventType::Reminded,
                'created_at' => $now,
            ]);

            $current->loadMissing('patient')->patient->notify(new FollowUpReminder($current));

            return $event;
        });
    }
}
