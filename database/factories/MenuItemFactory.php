<?php

namespace Database\Factories;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MenuItem>
 */
class MenuItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'menu_category_id' => MenuCategory::factory(),
            'name' => fake()->randomElement(['Indomie Goreng', 'Es Teh', 'Kopi Susu', 'Choki-choki', 'Roti Bakar']),
            'price' => fake()->randomElement([3_000, 5_000, 8_000, 12_000]),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
