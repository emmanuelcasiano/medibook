<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Doctor;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

final class UpdateDoctor
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Change a doctor's profile and the services the doctor offers. The list of services replaces the old one.
     * Existing appointments keep their service: only new bookings are affected.
     * The caller has already authorized this (DoctorPolicy::update). `doctor.updated` is written to the audit log in
     * the same transaction, with the names of the profile fields that changed and the ids of the services offered.
     *
     * @param  list<int>  $serviceIds
     */
    public function handle(User $actor, Doctor $doctor, string $specialty, ?string $bio, array $serviceIds): Doctor
    {
        // One transaction, so a failure while syncing can't leave a new bio next to the old services.
        return DB::transaction(function () use ($actor, $doctor, $specialty, $bio, $serviceIds): Doctor {
            $doctor->fill(['specialty' => $specialty, 'bio' => $bio]);

            $changed = array_keys($doctor->getDirty());
            $doctor->save();
            $doctor->services()->sync($serviceIds);

            $this->audit->record($actor, 'doctor.updated', $doctor, [
                'changed' => $changed,
                'service_ids' => $serviceIds,
            ]);

            return $doctor;
        });
    }
}
