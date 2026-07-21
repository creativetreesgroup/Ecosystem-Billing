<?php

use App\Domain\Devices\ControlDriver;
use App\Domain\Discounts\DiscountTarget;
use App\Domain\Wallet\Actions\PlayFromWalletAction;
use App\Domain\Wallet\Wallet;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Package;
use App\Models\Unit;
use App\Models\User;

beforeEach(function () {
    User::factory()->owner()->create(); // operator kios
    $this->unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $this->package = Package::factory()->for($this->unit->unitType)->create(['price' => 20_000, 'duration_minutes' => 60]);
    $this->customer = Customer::factory()->create();
    app(Wallet::class)->topUp($this->customer, 100_000);
});

/**
 * Promo otomatis berlaku TANPA kode: pelanggan beli paket, potongan langsung
 * masuk.
 */
test('an active promo auto-applies to a package purchase with no code', function () {
    Discount::factory()->promo()->percentage(10)->forTargets([DiscountTarget::Package])->create();

    $session = app(PlayFromWalletAction::class)->handle($this->customer->fresh(), $this->unit, $this->package);

    expect($session->total_amount)->toBe(18_000)   // 20.000 − 10%
        ->and($session->discount_amount)->toBe(2_000)
        ->and($this->customer->fresh()->balance)->toBe(82_000);
});

/**
 * Bila beberapa promo cocok, yang potongannya TERBESAR yang dipakai.
 */
test('the biggest applicable promo wins', function () {
    Discount::factory()->promo()->percentage(10)->forTargets([DiscountTarget::Package])->create();
    Discount::factory()->promo()->percentage(25)->forTargets([DiscountTarget::Package])->create();

    $session = app(PlayFromWalletAction::class)->handle($this->customer->fresh(), $this->unit, $this->package);

    expect($session->discount_amount)->toBe(5_000); // 25% dari 20.000
});

/**
 * Kode voucher menang atas promo otomatis (pelanggan memilih kodenya sendiri).
 */
test('a voucher code takes precedence over an auto promo', function () {
    Discount::factory()->promo()->percentage(10)->forTargets([DiscountTarget::Package])->create();
    Discount::factory()->percentage(30)->forTargets([DiscountTarget::Package])->create(['code' => 'HEMAT30']);

    $session = app(PlayFromWalletAction::class)->handle($this->customer->fresh(), $this->unit, $this->package, 'HEMAT30');

    expect($session->discount_amount)->toBe(6_000); // 30% voucher, bukan 10% promo
});

/**
 * Promo untuk permukaan lain tidak ikut: promo isi saldo tak memotong paket.
 */
test('a promo for another target does not apply', function () {
    Discount::factory()->promo()->percentage(10)->forTargets([DiscountTarget::TopUp])->create();

    $session = app(PlayFromWalletAction::class)->handle($this->customer->fresh(), $this->unit, $this->package);

    expect($session->fresh()->discount_amount)->toBe(0)
        ->and($session->total_amount)->toBe(20_000);
});

/**
 * Promo nonaktif / di luar jendela tak berlaku.
 */
test('an inactive or expired promo does not auto-apply', function () {
    Discount::factory()->promo()->percentage(10)->forTargets([DiscountTarget::Package])->create(['is_active' => false]);
    Discount::factory()->promo()->percentage(10)->forTargets([DiscountTarget::Package])->create(['ends_at' => now()->subDay()]);

    $session = app(PlayFromWalletAction::class)->handle($this->customer->fresh(), $this->unit, $this->package);

    expect($session->fresh()->discount_amount)->toBe(0);
});
