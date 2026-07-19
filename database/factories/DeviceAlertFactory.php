<?php

namespace Database\Factories;

use App\Domain\Devices\DeviceAlertStatus;
use App\Domain\Devices\DeviceAlertType;
use App\Models\DeviceAlert;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceAlert>
 */
class DeviceAlertFactory extends Factory
{
    public function definition(): array
    {
        return [
            'unit_id' => Unit::factory(),
            'type' => DeviceAlertType::PowerOffFailed,
            'message' => 'TV tidak merespons perintah mati.',
            'status' => DeviceAlertStatus::Open,
        ];
    }
}
