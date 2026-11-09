<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\FollowUpState;
use App\Enums\FollowUpStatus;
use App\Models\FollowUp;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ListFollowUps
{
    /** A booked or dismissed follow-up stays on the list this long after it last changed, then drops off. */
    public const int CLOSED_VISIBLE_DAYS = 30;

    /**
     * The follow-ups the staff page shows: every `open` one, plus the ones closed in the last 30 days, so the list
     * can't grow for ever (PROJECT.md section 9). Sorted by the state staff see (needs a call first), then by the day
     * the window opens, then oldest first, so the order never jumps between two loads.
     *
     * The `note` column is not selected. It is the doctor's message to the patient and can hold health detail, so
     * staff never get it (PROJECT.md section 3), not even in memory. The relations hold names and dates only.
     *
     * Three small queries, no N+1: the follow-ups, then their patients, doctors (with the user) and services in one
     * query each, and the booked visit for the ones that have one.
     *
     * @return Collection<int, FollowUp>
     */
    public function handle(CarbonImmutable $now): Collection
    {
        return FollowUp::query()
            ->select([
                'id', 'ulid', 'patient_id', 'doctor_id', 'service_id', 'source_appointment_id', 'booked_appointment_id',
                'window_starts_on', 'window_ends_on', 'same_doctor', 'status', 'last_reminded_at', 'created_at', 'updated_at',
            ])
            ->where(function (Builder $query) use ($now): void {
                $query->where('status', FollowUpStatus::Open->value)
                    ->orWhere('updated_at', '>=', $now->subDays(self::CLOSED_VISIBLE_DAYS));
            })
            ->with([
                'patient:id,name,email',
                'doctor.user:id,name',
                'service:id,name,duration_minutes',
                'bookedAppointment:id,starts_at',
            ])
            ->get()
            ->sort(fn (FollowUp $a, FollowUp $b): int => [$this->rank($a, $now), $a->window_starts_on->toDateString(), $a->id]
                <=> [$this->rank($b, $now), $b->window_starts_on->toDateString(), $b->id])
            ->values();
    }

    /** The position of the state in the enum, which is the order of the staff list. */
    private function rank(FollowUp $followUp, CarbonImmutable $now): int
    {
        return (int) array_search($followUp->state($now), FollowUpState::cases(), true);
    }
}
