<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\AppointmentStatus;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent to the patient about 24 hours before the start (PROJECT.md F9), by the `medibook:send-reminders` command.
 */
final class AppointmentReminder extends AppointmentNotification
{
    protected function sendsWhileStatus(): AppointmentStatus
    {
        return AppointmentStatus::Booked;
    }

    protected function subject(): string
    {
        return 'Reminder: your appointment tomorrow';
    }

    protected function intro(): string
    {
        return 'This is a reminder of your appointment at '.config('app.name').'.';
    }

    protected function finish(MailMessage $message): MailMessage
    {
        return $message
            ->line('If you can no longer come, please cancel or call the clinic.')
            ->action('My appointments', route('my.home'));
    }
}
