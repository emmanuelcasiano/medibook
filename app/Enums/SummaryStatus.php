<?php

declare(strict_types=1);

namespace App\Enums;

enum SummaryStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
