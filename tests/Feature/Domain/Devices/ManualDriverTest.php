<?php

use App\Domain\Devices\Capability;
use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\DeviceAlertType;
use App\Domain\Devices\DeviceManager;
use App\Domain\Devices\PowerState;
use App\Models\DeviceAlert;
use App\Models\Unit;

test('manual driver power off raises a device alert since it cannot verify anything', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);

    $driver = app(DeviceManager::class)->driverFor($unit);
    $result = $driver->powerOff($unit);

    expect($result->successful)->toBeTrue();
    expect(DeviceAlert::where('unit_id', $unit->id)->where('type', DeviceAlertType::PowerOffFailed)->exists())->toBeTrue();
});

test('manual driver never reports a supported capability', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $driver = app(DeviceManager::class)->driverFor($unit);

    expect($driver->supports($unit, Capability::Notify))->toBeFalse();
    expect($driver->state($unit))->toBe(PowerState::Unknown);
});

test('device manager throws a clear error for drivers not yet implemented', function (ControlDriver $driver) {
    $unit = Unit::factory()->create(['control_driver' => $driver]);

    app(DeviceManager::class)->driverFor($unit);
})->with([ControlDriver::HomeAssistant, ControlDriver::Tasmota])->throws(RuntimeException::class);
