<?php

namespace Database\Factories;

use App\Models\MenuCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuCategory>
 */
class MenuCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Snack', 'Makanan', 'Minuman']).' '.fake()->unique()->numberBetween(1, 9999),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
