<?php

declare(strict_types=1);

namespace App\Actions\Triage;

use App\Models\Appointment;
use App\Models\User;
use App\Models\VitalsRecord;
use App\Support\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class RecordVitals
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Saves the nurse's readings for one checked-in appointment. There is one record per appointment: the first save
     * creates it and every later save corrects it in place. The readings go through the model's `encrypted` cast, so
     * only ciphertext reaches the database, and they are never logged or copied anywhere else.
     *
     * The Policy runs twice (architecture.md section 3). Once before any lock, so a user who may not record learns
     * nothing about the booking. Then again on the row read under the lock: the model in the caller's hand may be old,
     * and a doctor who finishes the visit at the same moment must win. Under the lock the status is the current one, so
     * a save that lost the race is refused with the same 403 as one that came late, and nothing is written.
     *
     * Locking the appointment row also makes two saves at the same moment run one after the other, so they can't both
     * find no record and create two. The unique key on `appointment_id` is the backstop if some other path forgets.
     *
     * `appointments.vitals_taken_at` is set the first time only. It is a mark that staff may read, so it holds a time
     * and never a value. The audit row says that a record was created or corrected, never what it holds.
     *
     * @param  array{systolic: int, diastolic: int, pulse: int, temperature: float, oxygen_saturation: int, weight: float}  $readings
     *
     * @throws AuthorizationException When $nurse may not record for this appointment, or it is no longer checked in.
     */
    public function handle(User $nurse, Appointment $appointment, array $readings): VitalsRecord
    {
        $gate = Gate::forUser($nurse);
        $gate->authorize('create', [VitalsRecord::class, $appointment]);

        return DB::transaction(function () use ($nurse, $gate, $appointment, $readings): VitalsRecord {
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            $record = VitalsRecord::query()->where('appointment_id', $locked->id)->first();

            $gate->authorize($record === null ? 'create' : 'update', [VitalsRecord::class, $locked]);

            $now = CarbonImmutable::now();

            if ($record === null) {
                $record = VitalsRecord::create([
                    'appointment_id' => $locked->id,
                    'recorded_by' => $nurse->id,
                    'readings' => $readings,
                    'recorded_at' => $now,
                ]);
            } else {
                $record->update([
                    'recorded_by' => $nurse->id,
                    'readings' => $readings,
                    'recorded_at' => $now,
                ]);
            }

            if ($locked->vitals_taken_at === null) {
                $locked->update(['vitals_taken_at' => $now]);
            }

            // In the same transaction, so the log row exists if and only if the save does.
            $this->audit->record($nurse, 'vitals.saved', $record, [
                'mode' => $record->wasRecentlyCreated ? 'created' : 'updated',
            ]);

            return $record;
        });
    }
}
