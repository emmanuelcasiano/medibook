<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use App\Models\VisitNote;
use App\Support\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveVisitNote
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Saves the doctor's private note for one of their own appointments (PROJECT.md F8). There is one note per
     * appointment: the first save creates it and every later save edits it in place.
     *
     * `doctor_id` is the appointment's doctor, taken from the server, never from the request. The Policy has already
     * proved that this is the signed-in doctor. The body goes through the model's `encrypted` cast, so only ciphertext
     * reaches the database. The body is never logged and never copied anywhere else.
     *
     * This Action checks the Policy itself (architecture.md section 3), so a caller that skips the controller can't
     * skip the check either. The appointment Policy runs first, so a user who may not open the appointment learns
     * nothing about its note.
     *
     * Only a visit that took place takes a note (`AppointmentStatus::allowsVisitNote()`): checked in or completed. The
     * status is read again under the lock below, because the model in the caller's hand may be old. Today a checked-in
     * visit can only move on to completed, so no path makes a note land on a cancelled or no-show appointment. The
     * check stays so a later change (for example undoing a check-in) can't open that door by accident.
     *
     * The appointment row is locked while the note is read and written, so two saves at the same moment can't both
     * find no note and create two. The unique key on `appointment_id` is the backstop if some other code path forgets.
     *
     * Every save writes `notes.saved` to the audit log in the same transaction. The row says that a note was created
     * or edited and on which appointment, never what it says.
     *
     * @throws AuthorizationException When $actor is not the appointment's own doctor.
     * @throws ValidationException When the appointment's status does not take a note.
     */
    public function handle(User $actor, Appointment $appointment, string $body): VisitNote
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('view', $appointment);
        $gate->authorize('create', [VisitNote::class, $appointment]);

        return DB::transaction(function () use ($actor, $gate, $appointment, $body): VisitNote {
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->allowsVisitNote()) {
                // A booking will take a note after check-in. A cancellation or a no-show never will.
                $message = $locked->status === AppointmentStatus::Booked
                    ? 'A note can be written once the patient is checked in.'
                    : "A note can't be written on a ".strtolower($locked->status->label()).' appointment.';

                throw ValidationException::withMessages(['appointment' => $message]);
            }

            $note = VisitNote::query()->where('appointment_id', $appointment->id)->first();

            $created = $note === null;

            if ($note === null) {
                $note = VisitNote::create([
                    'appointment_id' => $appointment->id,
                    'doctor_id' => $appointment->doctor_id,
                    'body' => $body,
                ]);
            } else {
                $gate->authorize('update', $note);
                $note->update(['body' => $body]);
            }

            $this->audit->record($actor, 'notes.saved', $note, [
                'appointment_id' => $appointment->id,
                'created' => $created,
            ]);

            return $note;
        });
    }
}
