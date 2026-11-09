<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a phone call about a follow-up ended. A fixed list and not free text, so staff can't type health details into a
 * record that is not encrypted. `NotNeeded` is the only outcome that changes the follow-up itself (it is dismissed).
 */
enum FollowUpCallOutcome: string
{
    case NoAnswer = 'no_answer';
    case WillBookOnline = 'will_book_online';
    case BookedByPhone = 'booked_by_phone';
    case NotNeeded = 'not_needed';

    public function label(): string
    {
        return match ($this) {
            self::NoAnswer => 'No answer',
            self::WillBookOnline => 'Will book online',
            self::BookedByPhone => 'Booked by phone',
            self::NotNeeded => "Doesn't need the visit",
        };
    }
}
