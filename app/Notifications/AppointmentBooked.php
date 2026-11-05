<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Actions\Booking\CancelAppointment;
use App\Enums\AppointmentStatus;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent to the patient right after a booking (PROJECT.md F3, F9), and after staff move a booking, so the patient
 * learns the new time.
 */
final class AppointmentBooked extends AppointmentNotification
{
    protected function sendsWhileStatus(): AppointmentStatus
    {
        return AppointmentStatus::Booked;
    }

    protected function subject(): string
    {
        return 'Your appointment is booked';
    }

    protected function intro(): string
    {
        return 'Your appointment at '.config('app.name').' is booked.';
    }

    protected function finish(MailMessage $message): MailMessage
    {
        // The form lives behind login and the Policy; the link holds the ULID only.
        return $message
            ->line('Please fill in the intake form before your visit, so the doctor can prepare.')
            ->action('Fill in the intake form', route('my.appointments.intake', $this->appointment))
            ->line(sprintf('If you can no longer come, please cancel at least %d hours before the start.', CancelAppointment::PATIENT_CANCEL_HOURS));
    }
}
