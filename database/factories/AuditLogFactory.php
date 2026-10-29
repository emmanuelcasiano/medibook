<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => User::factory(),
            'action' => 'appointment.booked',
            'subject_type' => Appointment::class,
            'subject_id' => Appointment::factory(),
            'ip_hash' => hash('sha256', fake()->ipv4()),
            'metadata' => ['status_from' => null, 'status_to' => 'booked'],
            // The model has no automatic timestamps, so set it here.
            'created_at' => now(),
        ];
    }
}
