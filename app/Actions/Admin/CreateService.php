<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\Service;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The caller has already authorized this (ServicePolicy::create) and validated the input,
     * including that the slug is free (StoreServiceRequest). The unique index on `slug` is the last guard.
     * `service.created` is written to the audit log in the same transaction.
     */
    public function handle(
        User $actor,
        string $name,
        ?string $description,
        int $durationMinutes,
        int $bufferMinutes,
        bool $isActive,
    ): Service {
        return DB::transaction(function () use ($actor, $name, $description, $durationMinutes, $bufferMinutes, $isActive): Service {
            $service = Service::create([
                'name' => $name,
                'slug' => Str::slug($name),
                'description' => $description,
                'duration_minutes' => $durationMinutes,
                'buffer_minutes' => $bufferMinutes,
                'is_active' => $isActive,
            ]);

            $this->audit->record($actor, 'service.created', $service, ['slug' => $service->slug]);

            return $service;
        });
    }
}
