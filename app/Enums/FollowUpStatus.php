<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Stored states only. "Waiting", "Open now" and "Needs a call" are worked out from the window dates.
 */
enum FollowUpStatus: string
{
    case Open = 'open';
    case Booked = 'booked';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
