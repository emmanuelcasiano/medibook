<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\FollowUpCallOutcome;
use App\Enums\FollowUpEventType;
use App\Enums\FollowUpStatus;
use App\Models\FollowUp;
use App\Models\FollowUpEvent;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class LogFollowUpCall
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Record that $actor phoned the patient about $followUp and how it ended. The outcome is one of a fixed list (no
     * free text), so no health detail can be typed into a record staff can read. "Doesn't need the visit" also moves
     * the follow-up to `dismissed`, so it leaves the "needs a call" count. Every other outcome changes nothing but the
     * history: a booking is made with the Book button, which is what moves a follow-up to `booked`.
     *
     * Only the dismissal writes an audit row (`follow_up.dismissed`, in the same transaction): the call itself is
     * already kept, with its actor and time, in `follow_up_events`.
     *
     * Staff only (the Policy is asked here too, like the other Actions). In one transaction: the follow-up row is
     * locked and read again, so a call logged in a second tab can't act on a follow-up that was just booked.
     *
     * @throws AuthorizationException When $actor may not manage this follow-up.
     * @throws ValidationException When the follow-up is no longer `open`.
     */
    public function handle(User $actor, FollowUp $followUp, FollowUpCallOutcome $outcome): FollowUpEvent
    {
        Gate::forUser($actor)->authorize('manage', $followUp);

        return DB::transaction(function () use ($actor, $followUp, $outcome): FollowUpEvent {
            $current = FollowUp::query()->whereKey($followUp->id)->lockForUpdate()->firstOrFail();

            if ($current->status !== FollowUpStatus::Open) {
                throw ValidationException::withMessages(['follow_up' => 'This follow-up is not open any more.']);
            }

            $event = FollowUpEvent::create([
                'follow_up_id' => $current->id,
                'actor_id' => $actor->id,
                'type' => FollowUpEventType::Called,
                'outcome' => $outcome,
                'created_at' => now(),
            ]);

            if ($outcome === FollowUpCallOutcome::NotNeeded) {
                $current->update(['status' => FollowUpStatus::Dismissed]);

                $this->audit->record($actor, 'follow_up.dismissed', $current, [
                    'status_from' => FollowUpStatus::Open->value,
                    'status_to' => FollowUpStatus::Dismissed->value,
                    'outcome' => $outcome->value,
                ]);
            }

            return $event;
        });
    }
}
