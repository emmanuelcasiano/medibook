<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake('en_PH')->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => Role::Patient,
            'is_active' => true,
            'birth_date' => fake()->dateTimeBetween('-80 years', '-1 year')->format('Y-m-d'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    public function patient(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Role::Patient]);
    }

    /** Staff, doctors and admins don't give a birth date at registration. */
    public function staff(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Role::Staff, 'birth_date' => null]);
    }

    public function doctor(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Role::Doctor, 'birth_date' => null]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => Role::Admin, 'birth_date' => null]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
