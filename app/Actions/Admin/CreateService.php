<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Service;
use Illuminate\Support\Str;

final class CreateService
{
    /**
     * The caller has already authorized this (ServicePolicy::create) and validated the input,
     * including that the slug is free (StoreServiceRequest). The unique index on `slug` is the last guard.
     */
    public function handle(
        string $name,
        ?string $description,
        int $durationMinutes,
        int $bufferMinutes,
        bool $isActive,
    ): Service {
        return Service::create([
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => $description,
            'duration_minutes' => $durationMinutes,
            'buffer_minutes' => $bufferMinutes,
            'is_active' => $isActive,
        ]);
    }
}
