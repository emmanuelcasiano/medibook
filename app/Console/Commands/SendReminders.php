<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Booking\SendAppointmentReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('medibook:send-reminders')]
#[Description('Email a reminder for every booking that starts in about 24 hours and has not had one yet')]
final class SendReminders extends Command
{
    public function handle(SendAppointmentReminders $send): int
    {
        $this->info(sprintf('Queued %d reminder(s).', $send->handle()));

        return self::SUCCESS;
    }
}
