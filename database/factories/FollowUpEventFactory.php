<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FollowUpCallOutcome;
use App\Enums\FollowUpEventType;
use App\Models\FollowUp;
use App\Models\FollowUpEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FollowUpEvent>
 */
class FollowUpEventFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'follow_up_id' => FollowUp::factory(),
            'actor_id' => User::factory()->staff(),
            'type' => FollowUpEventType::Called,
            'outcome' => FollowUpCallOutcome::NoAnswer,
            'created_at' => now(),
        ];
    }

    public function reminded(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => FollowUpEventType::Reminded,
            'outcome' => null,
        ]);
    }
}
