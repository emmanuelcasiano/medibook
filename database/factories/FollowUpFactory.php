<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FollowUpStatus;
use App\Models\Appointment;
use App\Models\FollowUp;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FollowUp>
 */
class FollowUpFactory extends Factory
{
    /**
     * The patient and doctor come from the finished visit, so a follow-up can't point at
     * a patient or doctor who were not in that visit.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_appointment_id' => Appointment::factory()->completed(),
            'patient_id' => fn (array $attributes) => Appointment::query()->whereKey($attributes['source_appointment_id'])->firstOrFail()->patient_id,
            'doctor_id' => fn (array $attributes) => Appointment::query()->whereKey($attributes['source_appointment_id'])->firstOrFail()->doctor_id,
            'service_id' => Service::factory(),
            'window_starts_on' => now()->addDays(7)->toDateString(),
            'window_ends_on' => now()->addDays(11)->toDateString(),
            'same_doctor' => true,
            'note' => fake()->sentence(),
            'status' => FollowUpStatus::Open,
        ];
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FollowUpStatus::Open,
            'booked_appointment_id' => null,
        ]);
    }

    /** Booked from this follow-up. The new booking is for the same patient and doctor, after the window opens. */
    public function booked(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FollowUpStatus::Booked,
            'booked_appointment_id' => fn (array $resolved) => Appointment::factory()
                ->booked()
                ->state([
                    'patient_id' => $resolved['patient_id'],
                    'doctor_id' => $resolved['doctor_id'],
                    'service_id' => $resolved['service_id'],
                    'starts_at' => now()->addDays(8)->setTime(9, 0),
                ]),
        ]);
    }

    public function dismissed(): static
    {
        return $this->state(fn (array $attributes) => ['status' => FollowUpStatus::Dismissed]);
    }

    /** The window already closed and nobody booked: this is the "needs a call" case. */
    public function windowEnded(): static
    {
        return $this->state(fn (array $attributes) => [
            'window_starts_on' => now()->subDays(10)->toDateString(),
            'window_ends_on' => now()->subDays(6)->toDateString(),
        ]);
    }

    public function anyDoctor(): static
    {
        return $this->state(fn (array $attributes) => ['same_doctor' => false]);
    }
}
