<?php

use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\DeviceAlertType;
use App\Domain\Devices\DeviceManager;
use App\Domain\Devices\Jobs\VerifyUnitPoweredOffJob;
use App\Domain\Devices\PowerState;
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

// Fail loud (prinsip arsitektur #5): tidak bisa memastikan TV mati BUKAN
// alasan untuk diam. Kalau ini kembali "tidak beralert", verifikasi power-off
// jadi mati total untuk plug Tasmota offline & Home Assistant tak terjangkau.
test('the job raises an alert when it cannot confirm the TV is off', function () {
    Http::fake(['ha.test/*' => Http::failedConnection()]);
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::HomeAssistant, 'control_ref' => 'media_player.tv_ps01']);

    (new VerifyUnitPoweredOffJob($unit->id))->handle(app(DeviceManager::class));

    $alert = DeviceAlert::where('unit_id', $unit->id)->first();
    expect($alert)->not->toBeNull()
        ->and($alert->type)->toBe(DeviceAlertType::PowerOffFailed)
        ->and($alert->message)->toContain('tidak bisa dipastikan mati');
});

test('a stale Tasmota reading is reported rather than silently ignored', function () {
    $unit = Unit::factory()->create([
        'control_driver' => ControlDriver::Tasmota,
        'control_ref' => 'plug-ps01',
        'power_state' => PowerState::On,
        'last_seen_at' => now()->subMinutes(10), // basi -> state() = Unknown
    ]);

    (new VerifyUnitPoweredOffJob($unit->id))->handle(app(DeviceManager::class));

    expect(DeviceAlert::where('unit_id', $unit->id)->exists())->toBeTrue();
});

test('a manual unit is not double-alerted, since ManualDriver already alerts on power off', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);

    (new VerifyUnitPoweredOffJob($unit->id))->handle(app(DeviceManager::class));

    expect(DeviceAlert::where('unit_id', $unit->id)->exists())->toBeFalse();
});

test('the job does nothing when the unit was deleted before it ran', function () {
    (new VerifyUnitPoweredOffJob(999999))->handle(app(DeviceManager::class));

    expect(DeviceAlert::count())->toBe(0);
});
