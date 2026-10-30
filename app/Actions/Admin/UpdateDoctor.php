<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Doctor;
use Illuminate\Support\Facades\DB;

final class UpdateDoctor
{
    /**
     * Change a doctor's profile and the services the doctor offers. The list of services replaces the old one.
     * Existing appointments keep their service: only new bookings are affected.
     * The caller has already authorized this (DoctorPolicy::update).
     *
     * @param  list<int>  $serviceIds
     */
    public function handle(Doctor $doctor, string $specialty, ?string $bio, array $serviceIds): Doctor
    {
        // One transaction, so a failure while syncing can't leave a new bio next to the old services.
        return DB::transaction(function () use ($doctor, $specialty, $bio, $serviceIds): Doctor {
            $doctor->update(['specialty' => $specialty, 'bio' => $bio]);
            $doctor->services()->sync($serviceIds);

            return $doctor;
        });
    }
}
