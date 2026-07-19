<?php

use App\Domain\Devices\ControlDriver;
use App\Filament\Resources\Units\Schemas\UnitForm;
use App\Models\Outlet;
use App\Models\Unit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Http;

/**
 * Satu perangkat fisik = satu unit. Kalau dua unit menunjuk control_ref yang
 * sama, menutup sesi di unit B ikut mematikan TV unit A yang pelanggannya
 * masih bermain dan masih ditagih — makanya dijaga di level DB, bukan hanya UI.
 */
test('two units in the same outlet cannot share a control_ref', function () {
    $unit = Unit::factory()->create([
        'control_driver' => ControlDriver::HomeAssistant,
        'control_ref' => 'media_player.tv_bersama',
    ]);

    Unit::factory()->create([
        'outlet_id' => $unit->outlet_id,
        'control_driver' => ControlDriver::HomeAssistant,
        'control_ref' => 'media_player.tv_bersama',
    ]);
})->throws(UniqueConstraintViolationException::class);

test('manual units may all keep a null control_ref', function () {
    $first = Unit::factory()->create(['control_driver' => ControlDriver::Manual, 'control_ref' => null]);

    $second = Unit::factory()->create([
        'outlet_id' => $first->outlet_id,
        'control_driver' => ControlDriver::Manual,
        'control_ref' => null,
    ]);

    expect($second->exists)->toBeTrue();
});

test('the same control_ref is allowed in a different outlet, since that is a different device', function () {
    $first = Unit::factory()->create([
        'control_driver' => ControlDriver::HomeAssistant,
        'control_ref' => 'media_player.tv_01',
    ]);

    $second = Unit::factory()->create([
        'outlet_id' => Outlet::factory()->create()->id,
        'control_driver' => ControlDriver::HomeAssistant,
        'control_ref' => 'media_player.tv_01',
    ]);

    expect($second->exists)->toBeTrue();
});

test('discovery hides TVs already claimed by another unit', function () {
    config(['services.home_assistant.base_url' => 'http://ha.test', 'services.home_assistant.token' => 'secret-token']);
    Http::fake(['ha.test/api/states' => Http::response([
        ['entity_id' => 'media_player.sudah_dipakai', 'state' => 'on', 'attributes' => ['friendly_name' => 'TV Terpakai']],
        ['entity_id' => 'media_player.masih_bebas', 'state' => 'on', 'attributes' => ['friendly_name' => 'TV Bebas']],
    ])]);

    Unit::factory()->create([
        'control_driver' => ControlDriver::HomeAssistant,
        'control_ref' => 'media_player.sudah_dipakai',
    ]);

    $available = (new ReflectionMethod(UnitForm::class, 'availableTvs'))->invoke(null, null);

    expect($available)->toBe(['media_player.masih_bebas' => 'TV Bebas']);
});

test('a unit being edited still sees its own TV in the discovery list', function () {
    config(['services.home_assistant.base_url' => 'http://ha.test', 'services.home_assistant.token' => 'secret-token']);
    Http::fake(['ha.test/api/states' => Http::response([
        ['entity_id' => 'media_player.punya_sendiri', 'state' => 'on', 'attributes' => ['friendly_name' => 'TV Sendiri']],
    ])]);

    $unit = Unit::factory()->create([
        'control_driver' => ControlDriver::HomeAssistant,
        'control_ref' => 'media_player.punya_sendiri',
    ]);

    $available = (new ReflectionMethod(UnitForm::class, 'availableTvs'))->invoke(null, $unit);

    expect($available)->toBe(['media_player.punya_sendiri' => 'TV Sendiri']);
});
