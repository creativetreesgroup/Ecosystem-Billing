<?php

namespace Database\Factories;

use App\Domain\Settings\SettingKey;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Kunci ACAK tidak lagi masuk akal: kolomnya kini di-cast ke
            // SettingKey, dan kunci di luar enum akan meledak saat dibaca.
            'key' => fake()->randomElement(SettingKey::cases()),
            'value' => ['value' => fake()->numberBetween(1, 60)],
        ];
    }
}
