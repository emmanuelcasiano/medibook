<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Notifications\AppointmentReminder;
use Carbon\CarbonImmutable;

final class SendAppointmentReminders
{
    /** The window opens this many minutes before the start, 24 h less half an hour (PROJECT.md F9). */
    private const int WINDOW_FROM_MINUTES = 23 * 60 + 30;

    /** The window closes this many minutes before the start, 24 h plus half an hour. */
    private const int WINDOW_TO_MINUTES = 24 * 60 + 30;

    /**
     * Email a reminder to the patient of every `booked` appointment that starts in 23.5 to 24.5 hours and has not
     * had one yet, and mark it with `reminder_sent_at`. Returns how many reminders were queued.
     *
     * The command runs every 15 minutes and the window is 60 minutes wide, so each booking is inside it for about
     * four runs. Only the first run may send. `reminder_sent_at` is the guard, and it is claimed with one
     * conditional `UPDATE ... WHERE reminder_sent_at IS NULL AND status = 'booked'` before the notification is
     * queued. The database lets exactly one caller change the row, so two overlapping runs (or a retry after a crash)
     * can never both send, which a "read the column, send, then write it" order cannot promise.
     *
     * The trade-off: the mark means "queued", not "delivered". If the queue job fails for good (3 tries), it is in
     * `failed_jobs` and the patient gets no second reminder. One missed reminder is better than two emails, and the
     * appointment itself is not affected.
     *
     * The notification checks `shouldSend()` again when the worker runs, so a booking cancelled in between sends
     * nothing. Times are compared in UTC, the way `starts_at` is stored; a relative window needs no clinic timezone.
     */
    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $queued = 0;

        Appointment::query()
            ->where('status', AppointmentStatus::Booked)
            ->whereNull('reminder_sent_at')
            ->whereBetween('starts_at', [
                $now->addMinutes(self::WINDOW_FROM_MINUTES),
                $now->addMinutes(self::WINDOW_TO_MINUTES),
            ])
            ->with('patient')
            ->chunkById(100, function ($appointments) use ($now, &$queued): void {
                foreach ($appointments as $appointment) {
                    $claimed = Appointment::query()
                        ->whereKey($appointment->id)
                        ->where('status', AppointmentStatus::Booked)
                        ->whereNull('reminder_sent_at')
                        ->update(['reminder_sent_at' => $now]);

                    if ($claimed === 1) {
                        $appointment->patient->notify(new AppointmentReminder($appointment));
                        $queued++;
                    }
                }
            });

        return $queued;
    }
}
