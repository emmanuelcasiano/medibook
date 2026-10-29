<?php

declare(strict_types=1);

namespace App\Enums;

/** The "how long" select on the intake form (PROJECT.md F5). */
enum IntakeDuration: string
{
    case Today = 'today';
    case FewDays = 'few_days';
    case OneWeek = 'one_week';
    case FewWeeks = 'few_weeks';
    case MonthOrMore = 'month_or_more';

    public function label(): string
    {
        return match ($this) {
            self::Today => 'Since today',
            self::FewDays => 'A few days',
            self::OneWeek => 'About a week',
            self::FewWeeks => 'A few weeks',
            self::MonthOrMore => 'A month or more',
        };
    }
}
