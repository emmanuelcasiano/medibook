<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Models\Doctor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class ListAdvertisedDoctors
{
    /** The most doctors the landing page names. A clinic has a few; the booking step lists every one of them. */
    public const int MAX = 8;

    /**
     * The doctors the public landing page may name, by name. It must only promise what a patient can then do, so a
     * doctor is left out when their account is switched off (the `bookable` scope) or when they offer no active
     * service, because nobody could book them.
     *
     * The `user` is loaded with them: the name lives there, and strict mode throws on a lazy load.
     *
     * @return Collection<int, Doctor>
     */
    public function handle(): Collection
    {
        return Doctor::query()
            ->bookable()
            ->whereHas('services', fn (Builder $services) => $services->where('is_active', true))
            ->with('user')
            ->orderedByName()
            ->limit(self::MAX)
            ->get();
    }
}
