<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // unique() keeps the name, and so the slug, from repeating inside one test run.
        $name = 'Service '.fake()->unique()->numerify('####');

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'duration_minutes' => 30,
            'buffer_minutes' => 10,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    public function lasting(int $minutes, int $buffer = 0): static
    {
        return $this->state(fn (array $attributes) => [
            'duration_minutes' => $minutes,
            'buffer_minutes' => $buffer,
        ]);
    }
}
