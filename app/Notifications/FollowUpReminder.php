<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\FollowUpStatus;
use App\Models\FollowUp;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Staff's "Send reminder" email (PROJECT.md section 9): the doctor asked the patient to come back, and the patient has
 * not booked yet. Like the appointment emails (PROJECT.md F9) it is queued, mail only, and holds no health
 * information: the doctor, the visit type and the dates, and never the doctor's `note`. Email leaves our servers and
 * sits in inboxes we don't control.
 *
 * It waits for the commit, so a reminder that rolls back sends nothing. The follow-up goes into the queue as its id
 * only (SerializesModels), so no patient data sits in the jobs table.
 */
final class FollowUpReminder extends Notification implements ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> Seconds to wait before retry 2 and retry 3. */
    public array $backoff = [10, 60, 300];

    /** A follow-up deleted before the worker runs has nobody left to remind, so the job is dropped and not failed. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly FollowUp $followUp) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Checked by the worker just before sending. The queue can be minutes behind: a patient who booked in that time
     * must not get a "please book" email after their confirmation.
     */
    public function shouldSend(User $notifiable, string $channel): bool
    {
        return $this->followUp->status === FollowUpStatus::Open;
    }

    public function toMail(User $notifiable): MailMessage
    {
        $followUp = $this->followUp->loadMissing(['doctor.user', 'service']);

        $message = (new MailMessage)
            ->subject('Please book your follow-up visit')
            ->greeting(sprintf('Hello %s,', $notifiable->firstName()))
            ->line($followUp->same_doctor
                ? sprintf('%s would like to see you again at %s.', $followUp->doctor->user->name, config('app.name'))
                : sprintf('Your doctor would like to see you again at %s.', config('app.name')))
            ->line('Visit type: '.$followUp->service->name);

        // The dates are clinic days, so they are printed as they are, with no timezone conversion.
        $message->line(sprintf(
            'Please book a time between %s and %s.',
            $followUp->window_starts_on->format('j F Y'),
            $followUp->window_ends_on->format('j F Y'),
        ));

        return $message->action('Book my follow-up', route('my.home'));
    }
}
