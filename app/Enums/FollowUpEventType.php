<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What staff did about a follow-up. Both rows go on the staff panel's timeline (PROJECT.md section 9).
 */
enum FollowUpEventType: string
{
    case Called = 'called';
    case Reminded = 'reminded';
}
