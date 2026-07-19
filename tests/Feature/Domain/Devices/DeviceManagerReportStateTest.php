<?php

use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\DeviceAlertType;
use App\Domain\Devices\DeviceManager;
use App\Domain\Devices\Events\UnitPowerStateChanged;
use App\Domain\Devices\PowerState;
use App\Models\DeviceAlert;
use App\Models\Unit;
use Illuminate\Support\Facades\Event;

// §10 DoD: "Unit dibuat unreachable → ≤ 90 detik badge berubah + device_alert
// muncul" — reportState() adalah titik masuk tunggal units:poll-state &
// bridge:mqtt-listen melaporkan state, jadi diuji langsung di sini sekali
// untuk kedua caller sekaligus.
test('reportState does nothing but touch last_seen_at when the state is unchanged', function () {
    Event::fake([UnitPowerStateChanged::class]);
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::HomeAssistant, 'power_state' => PowerState::On, 'last_seen_at' => now()->subHour()]);

    app(DeviceManager::class)->reportState($unit, PowerState::On);

    Event::assertNotDispatched(UnitPowerStateChanged::class);
    expect(DeviceAlert::count())->toBe(0);
    expect($unit->fresh()->last_seen_at->diffInSeconds(now()))->toBeLessThan(2);
});

test('reportState broadcasts when the state changes to something other than unreachable', function () {
    Event::fake([UnitPowerStateChanged::class]);
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::HomeAssistant, 'power_state' => PowerState::Standby]);

    app(DeviceManager::class)->reportState($unit, PowerState::On);

    expect($unit->fresh()->power_state)->toBe(PowerState::On);
    Event::assertDispatched(UnitPowerStateChanged::class, fn ($event) => $event->unitId === $unit->id);
    expect(DeviceAlert::count())->toBe(0);
});

test('reportState raises a device_offline alert when a unit newly becomes unreachable', function () {
    Event::fake([UnitPowerStateChanged::class]);
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::HomeAssistant, 'code' => 'PS-01', 'power_state' => PowerState::On]);

    app(DeviceManager::class)->reportState($unit, PowerState::Unreachable);

    expect($unit->fresh()->power_state)->toBe(PowerState::Unreachable);
    Event::assertDispatched(UnitPowerStateChanged::class);
    $alert = DeviceAlert::where('unit_id', $unit->id)->first();
    expect($alert)->not->toBeNull();
    expect($alert->type)->toBe(DeviceAlertType::DeviceOffline);
    expect($alert->message)->toContain('PS-01');
});

test('reportState does not raise an alert when transitioning away from unreachable', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::HomeAssistant, 'power_state' => PowerState::Unreachable]);

    app(DeviceManager::class)->reportState($unit, PowerState::On);

    expect(DeviceAlert::count())->toBe(0);
});
