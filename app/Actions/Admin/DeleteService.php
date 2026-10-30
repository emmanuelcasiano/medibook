<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteService
{
    /**
     * Remove a service nobody has booked. A service with any appointment, in any status, is history
     * (a completed visit still has to show what it was), so it can only be turned off.
     * The doctors who offered it are unlinked first. The caller has already authorized this (ServicePolicy::delete).
     *
     * @throws ValidationException when the service has appointments
     */
    public function handle(Service $service): void
    {
        DB::transaction(function () use ($service): void {
            // Lock the row so a booking can't slip in between the check and the delete.
            // The foreign key on `appointments.service_id` (restrictOnDelete) refuses it anyway if one does.
            $locked = Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();

            if ($locked->appointments()->exists()) {
                throw ValidationException::withMessages([
                    'service' => "{$locked->name} has appointments, so it can't be deleted. Turn off \"Bookable\" instead.",
                ]);
            }

            $locked->doctors()->detach();
            $locked->delete();
        });
    }
}
