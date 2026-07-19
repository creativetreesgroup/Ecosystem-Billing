<?php

use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\DeviceManager;
use App\Domain\Devices\Drivers\TasmotaDriver;
use App\Models\Unit;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;

// §9.4: HA_TOKEN, kredensial MQTT, dan semua secret dilarang masuk log,
// response, exception message, atau broadcast payload. Menyambungkan
// Monolog\Handler\TestHandler ke logger aktif menangkap SEMUA baris log yang
// benar-benar ditulis lewat channel produksi (bukan tebakan lewat mock),
// setara dengan `grep -riE token storage/logs` tapi otomatis & berulang.
test('the home assistant token never appears in application logs when a device command fails', function () {
    $secretToken = 'ha-super-secret-token-'.Str::random(20);
    config(['services.home_assistant.base_url' => 'http://ha.test', 'services.home_assistant.token' => $secretToken]);
    Http::fake(['ha.test/*' => Http::failedConnection()]);

    $handler = new TestHandler;
    Log::getLogger()->pushHandler($handler);

    $unit = Unit::factory()->create(['control_driver' => ControlDriver::HomeAssistant, 'control_ref' => 'media_player.tv_ps01']);
    app(DeviceManager::class)->attempt($unit, fn ($driver) => $driver->powerOn($unit));
    app(DeviceManager::class)->attempt($unit, fn ($driver) => $driver->state($unit));

    expect(json_encode($handler->getRecords()))->not->toContain($secretToken);
});

test('the mqtt password never leaks into the command result message on a broker connection failure', function () {
    $secretPassword = 'mqtt-super-secret-password-'.Str::random(20);
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Tasmota, 'control_ref' => 'plug-ps01']);

    $driver = new TasmotaDriver(host: '127.0.0.1', port: 19999, username: 'ctb-app', password: $secretPassword);
    $result = $driver->powerOn($unit);

    expect($result->successful)->toBeFalse();
    expect($result->message)->not->toContain($secretPassword);
});

test('the mqtt password never appears in application logs on a broker connection failure', function () {
    $secretPassword = 'mqtt-super-secret-password-'.Str::random(20);
    config([
        'services.mqtt.host' => '127.0.0.1',
        'services.mqtt.port' => 19999,
        'services.mqtt.username' => 'ctb-app',
        'services.mqtt.password' => $secretPassword,
    ]);
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Tasmota, 'control_ref' => 'plug-ps01']);

    $handler = new TestHandler;
    Log::getLogger()->pushHandler($handler);

    app(DeviceManager::class)->attempt($unit, fn ($driver) => $driver->powerOn($unit));

    expect(json_encode($handler->getRecords()))->not->toContain($secretPassword);
});
