<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SummaryStatus;
use App\Models\Intake;
use App\Models\IntakeSummary;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntakeSummary>
 */
class IntakeSummaryFactory extends Factory
{
    /**
     * A ready summary with fake text. Seeding must not call the real AI.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'intake_id' => Intake::factory(),
            'status' => SummaryStatus::Ready,
            'summary' => [
                'chief_complaint' => fake()->sentence(6),
                'key_points' => [fake()->sentence(), fake()->sentence()],
                'allergies_and_meds' => 'Not given',
                'questions_for_doctor' => [fake()->sentence()],
            ],
            'model' => 'claude-haiku-5-5',
            'input_tokens' => fake()->numberBetween(150, 400),
            'output_tokens' => fake()->numberBetween(100, 250),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SummaryStatus::Pending,
            'summary' => null,
            'model' => null,
            'input_tokens' => null,
            'output_tokens' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SummaryStatus::Failed,
            'summary' => null,
            'error' => 'RuntimeException',
        ]);
    }

    public function skipped(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SummaryStatus::Skipped,
            'summary' => null,
            'model' => null,
            'input_tokens' => null,
            'output_tokens' => null,
        ]);
    }
}
