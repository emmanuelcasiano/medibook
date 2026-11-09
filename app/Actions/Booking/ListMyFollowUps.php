<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\FollowUpStatus;
use App\Models\FollowUp;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class ListMyFollowUps
{
    /**
     * The patient's own follow-ups that they can still book: `open`, the last day of the window is today or later
     * (clinic date), and the visit type is still switched on. A window that already closed has nothing left to book.
     * It becomes a "needs a call" row for staff in step L.4, not a button for the patient.
     *
     * Scoped to $patient here, so another patient's follow-up can never be in the result. The sidebar dot counts this
     * same query, so the dot and the list can't disagree.
     *
     * @return Builder<FollowUp>
     */
    public function query(User $patient): Builder
    {
        $today = now((string) config('app.clinic_timezone'))->toDateString();

        return FollowUp::query()
            ->whereBelongsTo($patient, 'patient')
            ->where('status', FollowUpStatus::Open)
            ->where('window_ends_on', '>=', $today)
            ->whereRelation('service', 'is_active', true);
    }

    /**
     * The list for "My appointments", soonest deadline first. It is short (a doctor asks once per visit and the
     * patient has at most a few visits open at a time), so it is not paginated.
     *
     * `service` and `doctor.user` are loaded for the page (strict mode throws on a lazy load).
     *
     * @return Collection<int, FollowUp>
     */
    public function handle(User $patient): Collection
    {
        return $this->query($patient)
            ->with(['service', 'doctor.user'])
            ->orderBy('window_ends_on')
            ->orderBy('id')
            ->get();
    }
}
