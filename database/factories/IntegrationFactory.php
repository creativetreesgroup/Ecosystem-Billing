<?php

namespace Database\Factories;

use App\Domain\Devices\IntegrationKey;
use App\Models\Integration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Integration>
 */
class IntegrationFactory extends Factory
{
    protected $model = Integration::class;

    public function definition(): array
    {
        return [
            'key' => IntegrationKey::HomeAssistant,
            'base_url' => 'http://192.168.100.10:8123',
            'token' => 'token-uji-'.fake()->uuid(),
            'is_active' => true,
        ];
    }

    public function withoutToken(): static
    {
        return $this->state(fn (): array => ['token' => null]);
    }
}
