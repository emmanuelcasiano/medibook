<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Doctor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Doctor>
 */
class DoctorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->doctor(),
            'specialty' => fake()->randomElement(['General Medicine', 'Pediatrics', 'Dermatology', 'Internal Medicine']),
            'bio' => fake()->paragraph(),
        ];
    }
}
