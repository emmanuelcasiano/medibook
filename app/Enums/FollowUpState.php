<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What staff see for a follow-up (PROJECT.md section 9). Never stored: it is worked out from the status and the window
 * dates by FollowUp::state(), so it can't go out of date overnight. The case order is the order of the staff list.
 */
enum FollowUpState: string
{
    case NeedsCall = 'call';
    case OpenNow = 'open';
    case Waiting = 'waiting';
    case Booked = 'booked';
    case Dismissed = 'dismissed';
}
