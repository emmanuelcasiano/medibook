<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

final class ListMyAppointments
{
    public const int PAST_PER_PAGE = 10;

    /**
     * The patient's own appointments in two lists.
     *
     * Upcoming: still `booked` or `checked_in` and starting after now (the same "future" as the limit of 3 in
     * BookAppointment). That limit keeps the list short, so it is not paginated. Earliest first.
     *
     * Past: everything else, including cancelled and no-show bookings, newest first. This list only grows, so it is
     * paginated.
     *
     * Both lists are scoped to $patient here, so another patient's booking can never appear in either one.
     *
     * @return array{upcoming: Collection<int, Appointment>, past: LengthAwarePaginator<int, Appointment>}
     */
    public function handle(User $patient): array
    {
        $now = CarbonImmutable::now();
        $open = [AppointmentStatus::Booked, AppointmentStatus::CheckedIn];

        // The doctor's name lives on the user, so load `doctor.user` too (strict mode throws on a lazy load).
        $mine = fn () => Appointment::query()
            ->whereBelongsTo($patient, 'patient')
            ->with(['service', 'doctor.user']);

        return [
            'upcoming' => $mine()
                ->whereIn('status', $open)
                ->where('starts_at', '>', $now)
                ->orderBy('starts_at')
                ->orderBy('id')
                ->get(),
            'past' => $mine()
                ->where(fn ($query) => $query->whereNotIn('status', $open)->orWhere('starts_at', '<=', $now))
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->paginate(self::PAST_PER_PAGE)
                ->withQueryString(),
        ];
    }
}
