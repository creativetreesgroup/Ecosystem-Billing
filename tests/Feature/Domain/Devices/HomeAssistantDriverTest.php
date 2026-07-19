<?php

use App\Domain\Devices\Capability;
use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\Drivers\HomeAssistantDriver;
use App\Domain\Devices\PowerState;
use App\Models\Unit;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.home_assistant.base_url' => 'http://ha.test', 'services.home_assistant.token' => 'secret-token']);
});

function haTestUnit(array $attributes = []): Unit
{
    return Unit::factory()->create([
        'control_driver' => ControlDriver::HomeAssistant,
        'control_ref' => 'media_player.tv_ps01',
        ...$attributes,
    ]);
}

function haTestDriver(): HomeAssistantDriver
{
    return new HomeAssistantDriver(
        baseUrl: config('services.home_assistant.base_url'),
        token: config('services.home_assistant.token'),
    );
}

test('power on calls the media_player turn_on service with the unit entity id', function () {
    Http::fake(['ha.test/api/services/media_player/turn_on' => Http::response([], 200)]);
    $unit = haTestUnit();

    $result = haTestDriver()->powerOn($unit);

    expect($result->successful)->toBeTrue();
    Http::assertSent(fn (Request $request) => $request->url() === 'http://ha.test/api/services/media_player/turn_on'
        && $request->hasHeader('Authorization', 'Bearer secret-token')
        && $request['entity_id'] === 'media_player.tv_ps01');
});

test('power off calls the media_player turn_off service', function () {
    Http::fake(['ha.test/api/services/media_player/turn_off' => Http::response([], 200)]);
    $unit = haTestUnit();

    $result = haTestDriver()->powerOff($unit);

    expect($result->successful)->toBeTrue();
    Http::assertSent(fn (Request $request) => $request->url() === 'http://ha.test/api/services/media_player/turn_off');
});

test('a non successful http status is reported as a failed command', function () {
    Http::fake(['ha.test/*' => Http::response('nope', 500)]);
    $unit = haTestUnit();

    $result = haTestDriver()->powerOff($unit);

    expect($result->successful)->toBeFalse();
    expect($result->message)->toContain('500');
});

test('a connection failure is reported as a failed command instead of throwing', function () {
    Http::fake(['ha.test/*' => Http::failedConnection()]);
    $unit = haTestUnit();

    $result = haTestDriver()->powerOn($unit);

    expect($result->successful)->toBeFalse();
});

test('state maps home assistant states to power states', function (string $haState, PowerState $expected) {
    Http::fake(['ha.test/api/states/*' => Http::response(['state' => $haState])]);
    $unit = haTestUnit();

    expect(haTestDriver()->state($unit))->toBe($expected);
})->with([
    ['on', PowerState::On],
    ['off', PowerState::Standby],
    ['standby', PowerState::Standby],
    ['unavailable', PowerState::Unreachable],
    ['whatever-else', PowerState::Unknown],
]);

test('state reports unreachable when home assistant cannot be contacted', function () {
    Http::fake(['ha.test/*' => Http::failedConnection()]);
    $unit = haTestUnit();

    expect(haTestDriver()->state($unit))->toBe(PowerState::Unreachable);
});

test('notify is skipped for units without the notify capability', function () {
    Http::fake();
    $unit = haTestUnit(['capabilities' => []]);

    $result = haTestDriver()->notify($unit, 'Waktu sesi hampir habis');

    expect($result->successful)->toBeTrue();
    Http::assertNothingSent();
});

test('notify calls the notify service for units that support it', function () {
    Http::fake(['ha.test/api/services/notify/notify' => Http::response([], 200)]);
    $unit = haTestUnit(['capabilities' => [Capability::Notify->value]]);

    $result = haTestDriver()->notify($unit, 'Waktu sesi hampir habis');

    expect($result->successful)->toBeTrue();
    Http::assertSent(fn (Request $request) => $request['message'] === 'Waktu sesi hampir habis'
        && $request['target'] === 'media_player.tv_ps01');
});
