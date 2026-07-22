<?php

namespace Database\Seeders;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use Illuminate\Database\Seeder;

/**
 * Menu contoh untuk demo/pengembangan. Harga & isinya jelas milik outlet —
 * owner mengubahnya sendiri lewat panel; ini sekadar titik awal supaya layar
 * kios tidak kosong saat pertama dicoba.
 */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $menu = [
            'Snack' => [
                ['Indomie Goreng', 8_000],
                ['Kentang Goreng', 12_000],
                ['Roti Bakar', 10_000],
            ],
            'Makanan' => [
                ['Nasi Goreng', 18_000],
                ['Ayam Geprek', 20_000],
            ],
            'Minuman' => [
                ['Es Teh', 5_000],
                ['Kopi Susu', 10_000],
                ['Air Mineral', 4_000],
            ],
        ];

        foreach (array_values($menu) as $categoryIndex => $items) {
            $name = array_keys($menu)[$categoryIndex];

            $category = MenuCategory::firstOrCreate(
                ['name' => $name],
                ['sort_order' => $categoryIndex, 'is_active' => true],
            );

            foreach ($items as $itemIndex => [$itemName, $price]) {
                MenuItem::firstOrCreate(
                    ['menu_category_id' => $category->id, 'name' => $itemName],
                    ['price' => $price, 'sort_order' => $itemIndex, 'is_active' => true],
                );
            }
        }
    }
}
