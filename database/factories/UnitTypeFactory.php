<?php

namespace Database\Factories;

use App\Models\Outlet;
use App\Models\UnitType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnitType>
 */
class UnitTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'outlet_id' => Outlet::factory(),
            'name' => fake()->randomElement(['Non-VIP', 'VIP', 'Sultan']),
            'hourly_rate' => fake()->randomElement([5000, 8000, 12000]),
            'sort_order' => 0,
        ];
    }
}
