<?php

use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\DeviceManager;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Unit;
use App\Models\User;
use Livewire\Livewire;

/**
 * Saat sesi dimulai, TV harus menyala DAN berhenti menampilkan QR.
 *
 * Perilakunya sudah ada — ketiga jalur mulai sesi memanggil powerOn() lalu
 * clearScreen() — tetapi tidak ada satu pun test yang menjaganya. clearScreen()
 * hanya diuji di level driver, jadi menghapus dua baris itu saat refactor tidak
 * membuat apa pun gagal, dan kegagalannya baru ketahuan di outlet: pemain sudah
 * membayar, tetapi layarnya masih memajang QR ajakan memindai. Pemain berikutnya
 * memindai QR yang sama dan menemukan unit ternyata sedang dipakai.
 *
 * Yang diuji perintahnya, bukan TV-nya. TV sungguhan butuh Home Assistant di
 * jaringan outlet dan tidak bisa dijangkau dari suite.
 */
beforeEach(function () {
    User::factory()->owner()->create();

    $this->unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $this->package = Package::factory()->for($this->unit->unitType)->create([
        'price' => 10_000,
        'duration_minutes' => 60,
        'is_active' => true,
    ]);
    $this->customer = Customer::factory()->create(['balance' => 50_000]);
});

test('starting a package session tells the television to wake and drop the QR', function () {
    $this->mock(DeviceManager::class, function ($mock) {
        $mock->shouldReceive('powerOn')->once();
        $mock->shouldReceive('clearScreen')->once();
        $mock->shouldReceive('reportState')->zeroOrMoreTimes();
    });

    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('packageId', $this->package->id)
        ->call('play')
        ->assertHasNoErrors();

    expect($this->unit->fresh()->activeSession)->not->toBeNull();
});

test('starting an open play session tells the television to wake and drop the QR', function () {
    $this->mock(DeviceManager::class, function ($mock) {
        $mock->shouldReceive('powerOn')->once();
        $mock->shouldReceive('clearScreen')->once();
        $mock->shouldReceive('reportState')->zeroOrMoreTimes();
    });

    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->call('startOpenPlay')
        ->assertHasNoErrors();

    expect($this->unit->fresh()->activeSession)->not->toBeNull();
});

test('the idle screen command skips units that are being played', function () {
    // tv:show-idle memilih unit lewat whereDoesntHave('activeSession'). Tanpa
    // itu, perintah terjadwal akan MENGEMBALIKAN QR ke layar setiap kali ia
    // berjalan — menimpa permainan yang sedang berlangsung.
    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('packageId', $this->package->id)
        ->call('play');

    $this->mock(DeviceManager::class, function ($mock) {
        $mock->shouldReceive('showIdleScreen')->never();
        $mock->shouldReceive('reportState')->zeroOrMoreTimes();
    });

    $this->artisan('tv:show-idle')->assertSuccessful();
});
