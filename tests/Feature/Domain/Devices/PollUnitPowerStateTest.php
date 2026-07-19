<?php

use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\Events\UnitPowerStateChanged;
use App\Domain\Devices\PowerState;
use App\Models\Unit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.home_assistant.base_url' => 'http://ha.test', 'services.home_assistant.token' => 'secret-token']);
});

test('it updates and broadcasts when a unit power state changed', function () {
    Event::fake([UnitPowerStateChanged::class]);
    Http::fake(['ha.test/api/states/*' => Http::response(['state' => 'on'])]);
    $unit = Unit::factory()->create([
        'control_driver' => ControlDriver::HomeAssistant,
        'control_ref' => 'media_player.tv_ps01',
        'power_state' => PowerState::Standby,
    ]);

    $this->artisan('units:poll-state')->assertExitCode(0);

    expect($unit->fresh()->power_state)->toBe(PowerState::On);
    Event::assertDispatched(UnitPowerStateChanged::class, fn ($event) => $event->unitId === $unit->id);
});

test('it does nothing when the state is unchanged', function () {
    Event::fake([UnitPowerStateChanged::class]);
    Http::fake(['ha.test/api/states/*' => Http::response(['state' => 'on'])]);
    Unit::factory()->create([
        'control_driver' => ControlDriver::HomeAssistant,
        'control_ref' => 'media_player.tv_ps01',
        'power_state' => PowerState::On,
    ]);

    $this->artisan('units:poll-state')->assertExitCode(0);

    Event::assertNotDispatched(UnitPowerStateChanged::class);
});

test('it skips manual and tasmota units entirely', function () {
    Event::fake([UnitPowerStateChanged::class]);
    Http::fake();
    Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    Unit::factory()->create(['control_driver' => ControlDriver::Tasmota]);

    $this->artisan('units:poll-state')->assertExitCode(0);

    Http::assertNothingSent();
    Event::assertNotDispatched(UnitPowerStateChanged::class);
});

test('a tv going unreachable is treated as a real state change and broadcast', function () {
    Event::fake([UnitPowerStateChanged::class]);
    Http::fake(['ha.test/*' => Http::failedConnection()]);
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::HomeAssistant, 'control_ref' => 'media_player.tv_ps01', 'power_state' => PowerState::On]);

    $this->artisan('units:poll-state')->assertExitCode(0);

    expect($unit->fresh()->power_state)->toBe(PowerState::Unreachable);
    Event::assertDispatched(UnitPowerStateChanged::class, fn ($event) => $event->unitId === $unit->id);
});
