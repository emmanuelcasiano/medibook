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

    public function label(): string
    {
        return match ($this) {
            self::CheckedIn => 'Checked in',
            self::NoShow => 'No-show',
            default => ucfirst($this->value),
        };
    }
}
