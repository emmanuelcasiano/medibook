<?php

declare(strict_types=1);

namespace App\Enums;

enum AppointmentStatus: string
{
    case Booked = 'booked';
    case CheckedIn = 'checked_in';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    /**
     * Statuses that hold a slot. Keep in sync with the `active_key` generated column
     * on `appointments`, which does the same check inside MySQL.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Booked, self::CheckedIn, self::Completed];
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    /**
     * Whether the doctor may write or edit the visit note. A note is about a visit that took place, so it starts when
     * the patient is checked in and stays open after the visit is finished (the doctor often writes it up afterwards).
     * A booking that has not started, a cancellation and a no-show have no visit to write about. This is the one place
     * the rule is set: the Action enforces it and the page uses it to show or hide the form.
     */
    public function allowsVisitNote(): bool
    {
        return match ($this) {
            self::CheckedIn, self::Completed => true,
            self::Booked, self::Cancelled, self::NoShow => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CheckedIn => 'Checked in',
            self::NoShow => 'No-show',
            default => ucfirst($this->value),
        };
    }
}
