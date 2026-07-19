<?php

namespace Database\Factories;

use App\Models\Package;
use App\Models\UnitType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    public function definition(): array
    {
        $hours = fake()->randomElement([1, 2, 3, 5]);

        return [
            'unit_type_id' => UnitType::factory(),
            'name' => "{$hours} Jam",
            'duration_minutes' => $hours * 60,
            'price' => $hours * 5000,
            'is_active' => true,
        ];
    }
}
