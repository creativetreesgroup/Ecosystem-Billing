<?php

use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\DeviceAlertType;
use App\Domain\Devices\DeviceManager;
use App\Domain\Devices\Jobs\VerifyUnitPoweredOffJob;
use App\Models\DeviceAlert;
use App\Models\Unit;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.home_assistant.base_url' => 'http://ha.test', 'services.home_assistant.token' => 'secret-token']);
});

test('device manager powerOff schedules a verification job 10 seconds later', function () {
    Queue::fake();
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);

    app(DeviceManager::class)->powerOff($unit);

    Queue::assertPushed(VerifyUnitPoweredOffJob::class, function (VerifyUnitPoweredOffJob $job) use ($unit) {
        return $job->unitId === $unit->id
            && $job->delay !== null
            && now()->diffInSeconds($job->delay, true) >= 9;
    });
});

test('the job raises a device alert when the tv still reports on after power-off', function () {
    Http::fake(['ha.test/api/states/*' => Http::response(['state' => 'on'])]);
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::HomeAssistant, 'control_ref' => 'media_player.tv_ps01']);

    (new VerifyUnitPoweredOffJob($unit->id))->handle(app(DeviceManager::class));

    expect(DeviceAlert::where('unit_id', $unit->id)->where('type', DeviceAlertType::PowerOffFailed)->exists())->toBeTrue();
});

test('the job does nothing when the tv correctly reports off', function () {
    Http::fake(['ha.test/api/states/*' => Http::response(['state' => 'off'])]);
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::HomeAssistant, 'control_ref' => 'media_player.tv_ps01']);

    (new VerifyUnitPoweredOffJob($unit->id))->handle(app(DeviceManager::class));

    expect(DeviceAlert::where('unit_id', $unit->id)->exists())->toBeFalse();
});

test('the job does nothing when the driver cannot be reached', function () {
    Http::fake(['ha.test/*' => Http::failedConnection()]);
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::HomeAssistant, 'control_ref' => 'media_player.tv_ps01']);

    (new VerifyUnitPoweredOffJob($unit->id))->handle(app(DeviceManager::class));

    expect(DeviceAlert::where('unit_id', $unit->id)->exists())->toBeFalse();
});

test('the job does nothing when the unit was deleted before it ran', function () {
    (new VerifyUnitPoweredOffJob(999999))->handle(app(DeviceManager::class));

    expect(DeviceAlert::count())->toBe(0);
});
