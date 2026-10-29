<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Doctor;
use App\Models\WorkingHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkingHour>
 */
class WorkingHourFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'doctor_id' => Doctor::factory(),
            'weekday' => 1,
            'starts_at' => '08:00:00',
            'ends_at' => '12:00:00',
        ];
    }

    public function onWeekday(int $weekday): static
    {
        return $this->state(fn (array $attributes) => ['weekday' => $weekday]);
    }

    public function between(string $startsAt, string $endsAt): static
    {
        return $this->state(fn (array $attributes) => ['starts_at' => $startsAt, 'ends_at' => $endsAt]);
    }
}
