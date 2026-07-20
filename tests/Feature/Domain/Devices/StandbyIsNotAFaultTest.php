<?php

use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\DeviceManager;
use App\Domain\Devices\NetworkScanner;
use App\Domain\Devices\PowerState;
use App\Models\Unit;
use Illuminate\Support\Facades\Http;

/**
 * Ditemukan saat UAT di TCL Android TV sungguhan, bukan dari membaca kode.
 *
 * Android TV MEMUTUS koneksi remote-nya saat standby, jadi Home Assistant
 * melaporkan "unavailable" — kata yang sama persis dengan saat TV dicabut.
 * Sebelum perbaikan ini, setiap unit yang menganggur melahirkan alert
 * "Tidak terhubung" sepanjang malam, dan gangguan sungguhan tenggelam di
 * antara ratusan yang palsu.
 *
 * Pembedanya: TV standby tetap hidup di jaringan (terbukti di perangkat asli
 * — ping menjawab, port 6466/8008 terbuka). TV yang benar-benar mati tidak.
 */
beforeEach(function () {
    config()->set('services.home_assistant.base_url', 'http://ha.test');
    config()->set('services.home_assistant.token', 'token-uji');

    $this->unit = Unit::factory()->create([
        'control_driver' => ControlDriver::HomeAssistant,
        'control_ref' => 'media_player.smart_tv_pro',
        'tv_mac' => 'd8:14:df:7f:7d:47',
    ]);

    Http::fake(['ha.test/api/states/*' => Http::response(['state' => 'unavailable'])]);
});

test('a television that is merely on standby is not reported as a fault', function () {
    $this->mock(NetworkScanner::class)
        ->shouldReceive('isMacOnNetwork')->with('d8:14:df:7f:7d:47')->andReturnTrue();

    expect(app(DeviceManager::class)->driverFor($this->unit)->state($this->unit))
        ->toBe(PowerState::Standby);
});

test('a television that has left the network really is unreachable', function () {
    $this->mock(NetworkScanner::class)
        ->shouldReceive('isMacOnNetwork')->andReturnFalse();

    expect(app(DeviceManager::class)->driverFor($this->unit)->state($this->unit))
        ->toBe(PowerState::Unreachable);
});

/**
 * Tanpa MAC tidak ada bukti apa pun untuk menyimpulkan "cuma standby", jadi
 * sistem tetap memilih kesimpulan yang memicu pemeriksaan.
 */
test('without a mac there is no evidence, so it stays unreachable', function () {
    $this->unit->update(['tv_mac' => null]);

    expect(app(DeviceManager::class)->driverFor($this->unit)->state($this->unit))
        ->toBe(PowerState::Unreachable);
});

test('standby no longer raises a device-offline alert', function () {
    $this->mock(NetworkScanner::class)->shouldReceive('isMacOnNetwork')->andReturnTrue();

    $manager = app(DeviceManager::class);
    $manager->reportState($this->unit, $manager->driverFor($this->unit)->state($this->unit));

    expect($this->unit->deviceAlerts()->count())->toBe(0)
        ->and($this->unit->fresh()->power_state)->toBe(PowerState::Standby);
});
