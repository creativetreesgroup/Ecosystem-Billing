<?php

namespace Database\Factories;

use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\PowerState;
use App\Models\Outlet;
use App\Models\Unit;
use App\Models\UnitType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'outlet_id' => Outlet::factory(),
            'unit_type_id' => UnitType::factory(),
            'code' => 'PS-'.fake()->unique()->numberBetween(1, 999),
            'control_driver' => ControlDriver::Manual,
            'control_ref' => null,
            'tv_mac' => null,
            'capabilities' => [],
            'power_state' => PowerState::Unknown,
            'is_active' => true,
        ];
    }
}
