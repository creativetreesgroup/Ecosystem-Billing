<?php

use App\Domain\Devices\DiscoveredDevice;
use App\Domain\Devices\NetworkScanner;
use App\Filament\Resources\Units\Pages\ListUnits;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * Pemindaian memakan waktu nyata di jaringan, jadi scanner-nya diganti palsu:
 * yang diuji di sini perkabelan aksinya, bukan protokol SSDP (itu diuji
 * terpisah lewat bagian-bagian murninya di NetworkScannerTest).
 */
test('the network scan action lists the televisions it finds', function () {
    $this->mock(NetworkScanner::class)
        ->shouldReceive('scanTelevisions')
        ->once()
        ->andReturn([
            new DiscoveredDevice(ip: '192.168.100.7', name: 'TCL Smart TV', manufacturer: 'TCL'),
        ]);

    $action = TestAction::make('scanNetwork');

    $component = Livewire::actingAs(User::factory()->owner()->create())
        ->test(ListUnits::class)
        ->mountAction($action)
        ->assertActionMounted($action);

    expect($component->instance()->scanResults)->toBe([
        ['ip' => '192.168.100.7', 'label' => 'TCL Smart TV'],
    ]);
});

/**
 * Jaringan sepi bukan error — modalnya tetap harus terbuka dan menjelaskan
 * apa yang perlu dicek, bukan menampilkan kotak kosong.
 */
test('the network scan action opens even when nothing answers', function () {
    $this->mock(NetworkScanner::class)
        ->shouldReceive('scanTelevisions')
        ->once()
        ->andReturn([]);

    $action = TestAction::make('scanNetwork');

    // Tanpa assertHasNoActionErrors(): modal kosong tidak punya form, jadi
    // tidak ada error bag untuk diperiksa — memanggilnya justru mencari
    // schema yang memang sengaja tidak ada.
    $component = Livewire::actingAs(User::factory()->owner()->create())
        ->test(ListUnits::class)
        ->mountAction($action)
        ->assertActionMounted($action);

    expect($component->instance()->scanResults)->toBe([]);
});
