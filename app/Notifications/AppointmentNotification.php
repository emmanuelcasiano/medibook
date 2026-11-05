<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The shared part of the appointment emails (PROJECT.md F9): queued, mail only, and holding the date, time, doctor and
 * service and nothing else. No intake, summary, notes, emergency flag or cancel reason is ever read here: email
 * leaves our servers and sits in inboxes we don't control, so it gets no health information.
 *
 * It waits for the commit (`ShouldQueueAfterCommit`), so a booking or cancel that rolls back never sends an email.
 * The appointment goes into the queue as its id only (SerializesModels), so no patient data sits in the jobs table.
 */
abstract class AppointmentNotification extends Notification implements ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> Seconds to wait before retry 2 and retry 3. */
    public array $backoff = [10, 60, 300];

    /** A booking deleted before the worker runs has nobody left to tell, so the job is dropped and not failed. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly Appointment $appointment) {}

    /** The status the appointment must still have when the worker sends, or the email would be out of date. */
    abstract protected function sendsWhileStatus(): AppointmentStatus;

    abstract protected function subject(): string;

    abstract protected function intro(): string;

    /**
     * Add the closing lines and the button. The details are already on the message.
     */
    abstract protected function finish(MailMessage $message): MailMessage;

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Checked by the worker just before sending. The queue can be minutes behind: a booking cancelled in that time
     * must not get a "booked" email after the "cancelled" one.
     */
    public function shouldSend(User $notifiable, string $channel): bool
    {
        return $this->appointment->status === $this->sendsWhileStatus();
    }

    public function toMail(User $notifiable): MailMessage
    {
        $appointment = $this->appointment->loadMissing(['doctor.user', 'service']);
        $startsAt = $appointment->starts_at->setTimezone((string) config('app.clinic_timezone'));

        $message = (new MailMessage)
            ->subject($this->subject())
            ->greeting(sprintf('Hello %s,', $notifiable->firstName()))
            ->line($this->intro())
            ->line('Date: '.$startsAt->format('l, j F Y'))
            ->line('Time: '.$startsAt->format('g:i A'))
            ->line('Doctor: '.$appointment->doctor->user->name)
            ->line('Service: '.$appointment->service->name);

        return $this->finish($message);
    }
}
