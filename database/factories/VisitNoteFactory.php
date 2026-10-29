<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\VisitNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisitNote>
 */
class VisitNoteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'appointment_id' => Appointment::factory(),
            // The note belongs to the doctor of the appointment, so the two can't disagree.
            'doctor_id' => fn (array $attributes) => Appointment::query()->whereKey($attributes['appointment_id'])->firstOrFail()->doctor_id,
            'body' => fake()->paragraph(),
        ];
    }
}
