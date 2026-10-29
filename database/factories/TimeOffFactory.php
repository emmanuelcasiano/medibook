<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Doctor;
use App\Models\TimeOff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeOff>
 */
class TimeOffFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->addDays(fake()->numberBetween(2, 20))->startOfDay();

        return [
            'doctor_id' => Doctor::factory(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addDay(),
            'reason' => fake()->randomElement(['Conference', 'Vacation', 'Personal day']),
        ];
    }
}
