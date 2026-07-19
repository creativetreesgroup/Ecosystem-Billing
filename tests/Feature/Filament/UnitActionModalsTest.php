<?php

use App\Domain\Billing\PaymentMethod;
use App\Domain\Devices\ControlDriver;
use App\Domain\Sessions\Actions\StartSessionAction;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Filament\Widgets\UnitGridWidget;
use App\Models\Package;
use App\Models\Unit;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * Catatan: isi modal TIDAK bisa dicek dengan assertSee. Filament v5 merender
 * modal di partial terpisah (wire:partial="action-modals") yang kosong pada
 * render awal, jadi assertSee selalu gagal walau modalnya normal. Yang benar
 * adalah memeriksa aksi mana yang ter-mount dan apa akibatnya.
 */
beforeEach(function () {
    $this->kasir = User::factory()->create();
    $this->unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
});

test('the start modal opens', function () {
    $action = TestAction::make('start')->table($this->unit);

    Livewire::actingAs($this->kasir)->test(UnitGridWidget::class)
        ->mountAction($action)
        ->assertActionMounted($action)
        ->assertHasNoActionErrors();
});

test('stopping an open-play session needs a payment method', function () {
    app(StartSessionAction::class)->handle($this->unit, $this->kasir, SessionType::Open);

    Livewire::actingAs($this->kasir)->test(UnitGridWidget::class)
        ->mountAction(TestAction::make('stop')->table($this->unit))
        ->callMountedAction()
        ->assertHasActionErrors(['payment_method']);

    expect($this->unit->activeSession()->exists())->toBeTrue();
});

test('an open-play session is only completed after the payment is confirmed', function () {
    app(StartSessionAction::class)->handle($this->unit, $this->kasir, SessionType::Open);

    $component = Livewire::actingAs($this->kasir)->test(UnitGridWidget::class)
        ->mountAction(TestAction::make('stop')->table($this->unit))
        ->setActionData(['payment_method' => PaymentMethod::Cash->value])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        // Langkah pertama TIDAK boleh menutup sesi — uangnya belum ditegaskan.
        ->assertSet('mountedActions.0.name', 'stopConfirm');

    expect($this->unit->activeSession()->exists())->toBeTrue();

    // Modal konfirmasi tidak punya form, jadi tidak ada error bag untuk dicek —
    // yang membuktikan langkah kedua berjalan adalah state sesinya di bawah.
    $component->callMountedAction();

    $session = $this->unit->rentalSessions()->sole();

    expect($session->status)->toBe(SessionStatus::Completed)
        ->and($session->payment_method)->toBe(PaymentMethod::Cash);
});

test('a prepaid package session is closed with a single confirmation', function () {
    $package = Package::factory()->for($this->unit->unitType)->create();
    app(StartSessionAction::class)->handle(
        $this->unit, $this->kasir, SessionType::Package, package: $package, paymentMethod: PaymentMethod::Cash,
    );

    Livewire::actingAs($this->kasir)->test(UnitGridWidget::class)
        ->mountAction(TestAction::make('stop')->table($this->unit))
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertActionNotMounted();

    expect($this->unit->rentalSessions()->sole()->status)->toBe(SessionStatus::Completed);
});

test('the extend modal opens for a package session', function () {
    $package = Package::factory()->for($this->unit->unitType)->create();
    app(StartSessionAction::class)->handle(
        $this->unit, $this->kasir, SessionType::Package, package: $package, paymentMethod: PaymentMethod::Cash,
    );

    $action = TestAction::make('extend')->table($this->unit);

    Livewire::actingAs($this->kasir)->test(UnitGridWidget::class)
        ->mountAction($action)
        ->assertActionMounted($action)
        ->assertHasNoActionErrors();
});
