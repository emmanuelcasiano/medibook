<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IntakeDuration;
use App\Models\Appointment;
use App\Models\Intake;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Intake>
 */
class IntakeFactory extends Factory
{
    /**
     * Fake answers only. The keys match the intake form (PROJECT.md F5).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'appointment_id' => Appointment::factory(),
            'answers' => [
                'reason' => fake()->randomElement([
                    'Cough that does not go away',
                    'Headache for several days',
                    'Skin rash on my arm',
                    'Yearly check-up',
                ]),
                'symptoms' => fake()->sentence(),
                'duration' => fake()->randomElement(IntakeDuration::cases())->value,
                'allergies' => fake()->randomElement(['None', 'Penicillin', 'Peanuts']),
                'medicines' => fake()->randomElement(['None', 'Paracetamol when needed', 'Vitamin C']),
                'fever' => fake()->boolean(),
                'pregnant' => false,
                'recent_surgery' => false,
            ],
            'submitted_at' => now(),
        ];
    }
}
