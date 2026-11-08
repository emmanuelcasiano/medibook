<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Enums\AppointmentStatus;
use App\Enums\FollowUpStatus;
use App\Models\Appointment;
use App\Models\FollowUp;
use App\Models\Service;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RequestFollowUp
{
    /** How far ahead the window may end. The mockup's "In 1 month" ends about 32 days out, so 30 would refuse it. */
    private const int MAX_DAYS_AHEAD = 60;

    /**
     * The doctor asks the patient of a finished visit to come back inside a date range (PROJECT.md section 9). It
     * saves an `open` follow-up and nothing more: nothing is booked for the patient, and no email goes out yet.
     *
     * The patient and the doctor come from the visit and the status is always `open`, never from the caller. The note
     * goes through the model's `encrypted` cast and is never logged or copied anywhere else.
     *
     * This Action checks the Policy itself (architecture.md section 3), so a caller that skips the controller can't
     * skip the check either. It runs before any lock or read, so a user who may not ask learns nothing about the visit.
     *
     * In one transaction, with the appointment row locked:
     * - the visit must be `completed` (CompleteAppointment calls this right after it sets that status, in its own
     *   transaction, so a refusal here also undoes the "finish");
     * - the service must be switched on and offered by this doctor, read again from the database;
     * - the window starts today or later (clinic date) and ends within MAX_DAYS_AHEAD days;
     * - the visit has no follow-up yet. The table has no unique index on the visit, so the lock is what makes this
     *   check safe against two requests at once.
     *
     * The `follow_up.requested` audit event is step L.5, so no audit row is written here.
     *
     * @throws AuthorizationException When $actor is not the appointment's own doctor.
     * @throws ValidationException When a rule above fails. The errors are keyed like the form fields.
     */
    public function handle(User $actor, Appointment $appointment, FollowUpData $data): FollowUp
    {
        Gate::forUser($actor)->authorize('requestFollowUp', $appointment);

        return DB::transaction(function () use ($appointment, $data): FollowUp {
            $visit = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if ($visit->status !== AppointmentStatus::Completed) {
                throw ValidationException::withMessages(['appointment' => 'A follow-up can be requested once the visit is finished.']);
            }

            // Read again, so a service switched off or taken off this doctor a moment ago is refused. One existence
            // check, and no lazy load of the doctor (strict mode).
            $offered = Service::query()
                ->whereKey($data->serviceId)
                ->where('is_active', true)
                ->whereHas('doctors', fn (Builder $doctors) => $doctors->whereKey($visit->doctor_id))
                ->exists();

            if (! $offered) {
                throw ValidationException::withMessages(['follow_up.service_id' => 'Pick a visit type from the list.']);
            }

            $today = now((string) config('app.clinic_timezone'))->startOfDay();

            if ($data->startsOn->lt($today)) {
                throw ValidationException::withMessages(['follow_up.starts_on' => 'The first day can not be in the past.']);
            }

            if ($data->endsOn->lt($data->startsOn)) {
                throw ValidationException::withMessages(['follow_up.ends_on' => 'The last day must be on or after the first day.']);
            }

            if ($data->endsOn->gt($today->addDays(self::MAX_DAYS_AHEAD))) {
                throw ValidationException::withMessages(['follow_up.ends_on' => 'The last day can be up to '.self::MAX_DAYS_AHEAD.' days from today.']);
            }

            if (FollowUp::query()->where('source_appointment_id', $visit->id)->exists()) {
                throw ValidationException::withMessages(['appointment' => 'A follow-up was already requested for this visit.']);
            }

            return FollowUp::create([
                'patient_id' => $visit->patient_id,
                'doctor_id' => $visit->doctor_id,
                'service_id' => $data->serviceId,
                'source_appointment_id' => $visit->id,
                'window_starts_on' => $data->startsOn,
                'window_ends_on' => $data->endsOn,
                'same_doctor' => $data->sameDoctor,
                'note' => $data->note,
                'status' => FollowUpStatus::Open,
            ]);
        });
    }
}
