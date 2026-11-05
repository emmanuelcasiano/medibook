<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\AppointmentStatus;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent to the patient when staff cancel their booking (PROJECT.md F9). A patient who cancels gets none: they already
 * saw it happen on screen. The reason staff typed is left out: it is free text and can hold health detail.
 */
final class AppointmentCancelled extends AppointmentNotification
{
    protected function sendsWhileStatus(): AppointmentStatus
    {
        return AppointmentStatus::Cancelled;
    }

    protected function subject(): string
    {
        return 'Your appointment is cancelled';
    }

    protected function intro(): string
    {
        return 'The clinic has cancelled this appointment at '.config('app.name').'.';
    }

    protected function finish(MailMessage $message): MailMessage
    {
        return $message
            ->line('Please call the clinic if you have questions, or book a new time online.')
            ->action('Book a new time', route('booking.services'));
    }
}
