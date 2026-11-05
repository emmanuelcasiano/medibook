<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Service;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Remove a service nobody has booked. A service with any appointment, in any status, is history
     * (a completed visit still has to show what it was), so it can only be turned off.
     * The doctors who offered it are unlinked first. The caller has already authorized this (ServicePolicy::delete).
     * `service.deleted` is written to the audit log in the same transaction.
     *
     * @throws ValidationException when the service has appointments
     */
    public function handle(User $actor, Service $service): void
    {
        DB::transaction(function () use ($actor, $service): void {
            // Lock the row so a booking can't slip in between the check and the delete.
            // The foreign key on `appointments.service_id` (restrictOnDelete) refuses it anyway if one does.
            $locked = Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();

            if ($locked->appointments()->exists()) {
                throw ValidationException::withMessages([
                    'service' => "{$locked->name} has appointments, so it can't be deleted. Turn off \"Bookable\" instead.",
                ]);
            }

            // Logged before the delete: the logger refuses a subject that is no longer saved. The row keeps the id.
            $this->audit->record($actor, 'service.deleted', $locked, ['slug' => $locked->slug]);

            $locked->doctors()->detach();
            $locked->delete();
        });
    }
}
