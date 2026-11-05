<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Service;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

final class UpdateService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Change a service. The slug stays as it is. Bookings keep the times they already have:
     * a new duration or buffer only applies to bookings made from now on.
     * The caller has already authorized this (ServicePolicy::update).
     *
     * `service.updated` is written to the audit log in the same transaction. It lists the names of the fields that
     * changed, not their values, and it is written even when nothing changed (the admin did press Save).
     */
    public function handle(
        User $actor,
        Service $service,
        string $name,
        ?string $description,
        int $durationMinutes,
        int $bufferMinutes,
        bool $isActive,
    ): Service {
        return DB::transaction(function () use ($actor, $service, $name, $description, $durationMinutes, $bufferMinutes, $isActive): Service {
            $service->fill([
                'name' => $name,
                'description' => $description,
                'duration_minutes' => $durationMinutes,
                'buffer_minutes' => $bufferMinutes,
                'is_active' => $isActive,
            ]);

            $changed = array_keys($service->getDirty());
            $service->save();

            $this->audit->record($actor, 'service.updated', $service, ['changed' => $changed]);

            return $service;
        });
    }
}
