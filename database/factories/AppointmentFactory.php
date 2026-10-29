<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * `ends_at` and `blocked_until` are worked out from the service, so a state like startingIn()
     * can never leave a booking whose end or buffer disagrees with its service.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => User::factory()->patient(),
            'doctor_id' => Doctor::factory(),
            'service_id' => Service::factory(),
            'starts_at' => now()
                ->addDays(fake()->numberBetween(1, 14))
                ->setTime(fake()->numberBetween(8, 16), fake()->randomElement([0, 15, 30, 45])),
            'ends_at' => fn (array $attributes) => CarbonImmutable::parse($attributes['starts_at'])
                ->addMinutes(Service::query()->whereKey($attributes['service_id'])->firstOrFail()->duration_minutes),
            'blocked_until' => fn (array $attributes) => CarbonImmutable::parse($attributes['ends_at'])
                ->addMinutes(Service::query()->whereKey($attributes['service_id'])->firstOrFail()->buffer_minutes),
            'status' => AppointmentStatus::Booked,
            'emergency_flag' => false,
        ];
    }

    public function booked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AppointmentStatus::Booked,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancel_reason' => null,
        ]);
    }

    /** Cancelled by the patient themselves. Pass a staff user to `cancelled_by` for a staff cancel. */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AppointmentStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => fn (array $resolved) => $resolved['patient_id'],
        ]);
    }

    public function checkedIn(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AppointmentStatus::CheckedIn,
            'checked_in_at' => fn (array $resolved) => $resolved['starts_at'],
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AppointmentStatus::Completed,
            'checked_in_at' => fn (array $resolved) => $resolved['starts_at'],
        ]);
    }

    public function noShow(): static
    {
        return $this->state(fn (array $attributes) => ['status' => AppointmentStatus::NoShow]);
    }

    public function withEmergencyFlag(): static
    {
        return $this->state(fn (array $attributes) => ['emergency_flag' => true]);
    }

    /** Start this far from now (use a negative number for the past). Seconds are cut off. */
    public function startingIn(int $days = 0, int $hours = 0, int $minutes = 0): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->addDays($days)->addHours($hours)->addMinutes($minutes)->startOfMinute(),
        ]);
    }
}
