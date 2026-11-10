<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\User;
use App\Models\VitalsRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VitalsRecord>
 */
class VitalsRecordFactory extends Factory
{
    /**
     * Fake, ordinary-looking readings. Every value sits well inside the plausibility ranges of `SaveVitalsRequest`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'appointment_id' => Appointment::factory()->checkedIn(),
            'recorded_by' => User::factory()->nurse(),
            'readings' => [
                'systolic' => fake()->numberBetween(100, 135),
                'diastolic' => fake()->numberBetween(60, 85),
                'pulse' => fake()->numberBetween(60, 95),
                'temperature' => fake()->randomFloat(1, 36.1, 37.4),
                'oxygen_saturation' => fake()->numberBetween(95, 100),
                'weight' => fake()->randomFloat(1, 45, 95),
            ],
            'recorded_at' => now(),
        ];
    }

    /** Records for this appointment, and the appointment gets the "vitals taken" mark the Action would set. */
    public function forAppointment(Appointment $appointment): static
    {
        return $this
            ->state(['appointment_id' => $appointment->id])
            ->afterCreating(fn (): bool => $appointment->update(['vitals_taken_at' => now()]));
    }
}
