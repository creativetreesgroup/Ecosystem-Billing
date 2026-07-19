<?php

namespace Database\Factories;

use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Models\RentalSession;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RentalSession>
 */
class RentalSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'unit_id' => Unit::factory(),
            'opened_by' => User::factory(),
            'customer_name' => fake()->firstName(),
            'type' => SessionType::Open,
            'started_at' => now(),
            'ends_at' => null,
            'status' => SessionStatus::Active,
            'expiry_token' => (string) Str::uuid(),
            'base_amount' => 0,
            'extra_amount' => 0,
        ];
    }

    public function package(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => SessionType::Package,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SessionStatus::Completed,
            'ended_at' => now(),
            'total_amount' => 0,
        ]);
    }
}
