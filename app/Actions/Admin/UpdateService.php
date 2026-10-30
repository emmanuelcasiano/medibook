<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Service;

final class UpdateService
{
    /**
     * Change a service. The slug stays as it is. Bookings keep the times they already have:
     * a new duration or buffer only applies to bookings made from now on.
     * The caller has already authorized this (ServicePolicy::update).
     */
    public function handle(
        Service $service,
        string $name,
        ?string $description,
        int $durationMinutes,
        int $bufferMinutes,
        bool $isActive,
    ): Service {
        $service->update([
            'name' => $name,
            'description' => $description,
            'duration_minutes' => $durationMinutes,
            'buffer_minutes' => $bufferMinutes,
            'is_active' => $isActive,
        ]);

        return $service;
    }
}
