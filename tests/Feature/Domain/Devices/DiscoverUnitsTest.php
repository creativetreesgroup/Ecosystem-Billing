<?php

use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\DeviceManager;
use App\Models\Unit;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.home_assistant.base_url' => 'http://ha.test', 'services.home_assistant.token' => 'secret-token']);
});

function fakeHaStates(array $entities): void
{
    Http::fake(['ha.test/api/states' => Http::response($entities)]);
}

test('discovery returns only media_player entities, keyed by entity id', function () {
    fakeHaStates([
        ['entity_id' => 'media_player.tv_ps01', 'state' => 'on', 'attributes' => ['friendly_name' => 'TV Ruang 1']],
        ['entity_id' => 'media_player.tv_ps02', 'state' => 'off', 'attributes' => ['friendly_name' => 'TV Ruang 2']],
        ['entity_id' => 'light.dapur', 'state' => 'on', 'attributes' => ['friendly_name' => 'Lampu Dapur']],
        ['entity_id' => 'sun.sun', 'state' => 'above_horizon', 'attributes' => []],
    ]);

    $discovered = app(DeviceManager::class)->homeAssistant()->discoverMediaPlayers();

    expect($discovered)->toBe([
        'media_player.tv_ps01' => 'TV Ruang 1',
        'media_player.tv_ps02' => 'TV Ruang 2',
    ]);
});

test('discovery falls back to the entity id when home assistant has no friendly name', function () {
    fakeHaStates([
        ['entity_id' => 'media_player.tv_tanpa_nama', 'state' => 'on', 'attributes' => []],
    ]);

    expect(app(DeviceManager::class)->homeAssistant()->discoverMediaPlayers())
        ->toBe(['media_player.tv_tanpa_nama' => 'media_player.tv_tanpa_nama']);
});

test('discovery returns nothing instead of throwing when home assistant is unreachable', function () {
    Http::fake(['ha.test/*' => Http::failedConnection()]);

    expect(app(DeviceManager::class)->homeAssistant()->discoverMediaPlayers())->toBe([]);
});

test('the command flags which detected TVs are not paired to a unit yet', function () {
    fakeHaStates([
        ['entity_id' => 'media_player.sudah_dipakai', 'state' => 'on', 'attributes' => ['friendly_name' => 'TV Terpasang']],
        ['entity_id' => 'media_player.masih_bebas', 'state' => 'on', 'attributes' => ['friendly_name' => 'TV Baru']],
    ]);

    Unit::factory()->create([
        'code' => 'PS-09',
        'control_driver' => ControlDriver::HomeAssistant,
        'control_ref' => 'media_player.sudah_dipakai',
    ]);

    $this->artisan('units:discover')
        ->expectsOutputToContain('dipakai unit PS-09')
        ->expectsOutputToContain('BELUM dipakai')
        ->expectsOutputToContain('1 TV terdeteksi belum dipasangkan')
        ->assertExitCode(0);
});

test('the command explains what to check when nothing is detected', function () {
    fakeHaStates([]);

    $this->artisan('units:discover')
        ->expectsOutputToContain('Tidak ada TV terdeteksi')
        ->assertExitCode(0);
});
