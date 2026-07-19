<?php

use App\Domain\Devices\Capability;
use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\Drivers\TasmotaDriver;
use App\Domain\Devices\PowerState;
use App\Models\Unit;

function tasmotaTestUnit(array $attributes = []): Unit
{
    return Unit::factory()->create([
        'control_driver' => ControlDriver::Tasmota,
        'control_ref' => 'plug-ps01',
        ...$attributes,
    ]);
}

function tasmotaTestDriver(): TasmotaDriver
{
    // Loopback port nothing listens on: connect() fails fast (ECONNREFUSED)
    // instead of waiting out the full connect timeout, keeping the test quick.
    return new TasmotaDriver(host: '127.0.0.1', port: 19999, username: null, password: null);
}

test('a broker connection failure is reported as a failed command instead of throwing', function () {
    $unit = tasmotaTestUnit();

    $result = tasmotaTestDriver()->powerOn($unit);

    expect($result->successful)->toBeFalse();
    expect($result->message)->toContain('MQTT');
});

test('state reads the db-cached power state when it was seen recently', function () {
    $unit = tasmotaTestUnit(['power_state' => PowerState::On, 'last_seen_at' => now()->subSeconds(30)]);

    expect(tasmotaTestDriver()->state($unit))->toBe(PowerState::On);
});

test('state is unknown once the cached reading goes stale', function () {
    $unit = tasmotaTestUnit(['power_state' => PowerState::On, 'last_seen_at' => now()->subMinutes(5)]);

    expect(tasmotaTestDriver()->state($unit))->toBe(PowerState::Unknown);
});

test('state is unknown when the unit has never been seen', function () {
    $unit = tasmotaTestUnit(['power_state' => PowerState::Unknown, 'last_seen_at' => null]);

    expect(tasmotaTestDriver()->state($unit))->toBe(PowerState::Unknown);
});

test('a smart plug never reports a supported capability', function () {
    $unit = tasmotaTestUnit();

    expect(tasmotaTestDriver()->supports($unit, Capability::Notify))->toBeFalse();
});

test('notify is a no-op success since a smart plug cannot display anything', function () {
    $unit = tasmotaTestUnit();

    $result = tasmotaTestDriver()->notify($unit, 'Waktu sesi hampir habis');

    expect($result->successful)->toBeTrue();
});
