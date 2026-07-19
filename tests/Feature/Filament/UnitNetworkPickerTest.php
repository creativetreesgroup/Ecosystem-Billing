<?php

use App\Domain\Devices\NetworkScanner;
use App\Filament\Resources\Units\Pages\CreateUnit;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('services.home_assistant.base_url', 'http://ha.test');
    config()->set('services.home_assistant.token', 'token-uji');

    $this->mock(NetworkScanner::class, function ($mock) {
        $mock->shouldReceive('televisionsForPicker')->andReturn([
            'd8:14:df:7f:7d:47' => ['ip' => '192.168.100.7', 'name' => 'TCL Smart TV', 'label' => 'TCL Smart TV (192.168.100.7)'],
        ]);
        $mock->shouldReceive('televisionOptions')->andReturn([
            'd8:14:df:7f:7d:47' => 'TCL Smart TV (192.168.100.7)',
        ]);
    });
});

function haStates(array $players): void
{
    Http::fake(['ha.test/api/states' => Http::response(array_map(fn (array $p) => [
        'entity_id' => $p[0],
        'attributes' => ['friendly_name' => $p[1]],
    ], $players))]);
}

$pick = fn () => TestAction::make('scanForMac')->schemaComponent('tv_mac');

test('picking a scanned tv fills both the mac and the home assistant entity', function () use ($pick) {
    haStates([['media_player.tcl_smart_tv', 'TCL Smart TV'], ['media_player.dapur', 'Speaker Dapur']]);

    Livewire::actingAs(User::factory()->owner()->create())
        ->test(CreateUnit::class)
        ->mountAction($pick())
        ->setActionData(['mac' => 'd8:14:df:7f:7d:47'])
        ->callMountedAction()
        ->assertSchemaStateSet([
            'tv_mac' => 'd8:14:df:7f:7d:47',
            'control_ref' => 'media_player.tcl_smart_tv',
        ]);
});

/**
 * Menebak di sini berarti unit bisa mengendalikan TV milik unit LAIN — dan itu
 * baru ketahuan setelah pelanggan duduk di depan layar yang tiba-tiba mati.
 * Kalau kecocokannya meragukan, control_ref sengaja dibiarkan kosong.
 */
test('it refuses to guess the entity when two of them share a name', function () use ($pick) {
    haStates([['media_player.tcl_a', 'TCL Smart TV'], ['media_player.tcl_b', 'TCL Smart TV']]);

    Livewire::actingAs(User::factory()->owner()->create())
        ->test(CreateUnit::class)
        ->mountAction($pick())
        ->setActionData(['mac' => 'd8:14:df:7f:7d:47'])
        ->callMountedAction()
        ->assertSchemaStateSet([
            'tv_mac' => 'd8:14:df:7f:7d:47',
            'control_ref' => null,
        ]);
});
