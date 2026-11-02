<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Models\Appointment;
use App\Models\User;
use App\Models\VisitNote;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class SaveVisitNote
{
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
     * The appointment row is locked while the note is read and written. The table has no unique key on
     * `appointment_id`, so without the lock two saves at the same moment could both find no note and create two.
     *
     * @throws AuthorizationException When $actor is not the appointment's own doctor.
     */
    public function handle(User $actor, Appointment $appointment, string $body): VisitNote
    {
        $gate = Gate::forUser($actor);
        $gate->authorize('view', $appointment);
        $gate->authorize('create', [VisitNote::class, $appointment]);

        return DB::transaction(function () use ($gate, $appointment, $body): VisitNote {
            Appointment::query()->whereKey($appointment->id)->lockForUpdate()->first();

            $note = VisitNote::query()->where('appointment_id', $appointment->id)->first();

            if ($note === null) {
                return VisitNote::create([
                    'appointment_id' => $appointment->id,
                    'doctor_id' => $appointment->doctor_id,
                    'body' => $body,
                ]);
            }

            $gate->authorize('update', $note);
            $note->update(['body' => $body]);

            return $note;
        });
    }
}
