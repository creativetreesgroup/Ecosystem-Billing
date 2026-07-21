<?php

use App\Domain\Devices\ControlDriver;
use App\Domain\Discounts\Exceptions\DiscountNotApplicableException;
use App\Domain\Wallet\Actions\PlayFromWalletAction;
use App\Domain\Wallet\Wallet;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\DiscountRedemption;
use App\Models\Package;
use App\Models\Unit;
use App\Models\User;

beforeEach(function () {
    User::factory()->owner()->create(); // operator kios
    $this->customer = Customer::factory()->create();
    $this->unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $this->package = Package::factory()->for($this->unit->unitType)->create(['price' => 25_000, 'duration_minutes' => 60]);
    app(Wallet::class)->topUp($this->customer, 100_000);
});

test('a valid voucher charges the discounted price and records the redemption', function () {
    Discount::factory()->percentage(20)->create(['code' => 'HEMAT20']);

    $session = app(PlayFromWalletAction::class)->handle($this->customer->fresh(), $this->unit, $this->package, 'HEMAT20');

    // Ditagih setelah diskon: 25.000 − 20% = 20.000. Saldo 100k → 80k.
    expect($this->customer->fresh()->balance)->toBe(80_000)
        ->and($this->customer->fresh()->ledgerBalance())->toBe(80_000)
        // base_amount = harga list, total_amount = setelah diskon.
        ->and($session->base_amount)->toBe(25_000)
        ->and($session->total_amount)->toBe(20_000);

    // Pemakaian tercatat & tertaut ke sesinya.
    $redemption = DiscountRedemption::where('rental_session_id', $session->id)->first();

    expect($redemption)->not->toBeNull()
        ->and($redemption->amount)->toBe(5_000)
        ->and($redemption->customer_id)->toBe($this->customer->id);
});

test('without a voucher the full price is charged', function () {
    $session = app(PlayFromWalletAction::class)->handle($this->customer->fresh(), $this->unit, $this->package);

    expect($this->customer->fresh()->balance)->toBe(75_000)
        ->and($session->total_amount)->toBe(25_000)
        ->and(DiscountRedemption::count())->toBe(0);
});

test('an invalid voucher stops the purchase and charges nothing', function () {
    expect(fn () => app(PlayFromWalletAction::class)->handle($this->customer->fresh(), $this->unit, $this->package, 'NGACO'))
        ->toThrow(DiscountNotApplicableException::class);

    expect($this->customer->fresh()->balance)->toBe(100_000) // tak berubah
        ->and($this->unit->fresh()->activeSession)->toBeNull();
});

/**
 * Diskon menurunkan yang harus dibayar: pelanggan yang saldonya tak cukup untuk
 * harga penuh tetap bisa main bila setelah voucher jadi terjangkau.
 */
test('a voucher can make an otherwise-unaffordable package affordable', function () {
    $poor = Customer::factory()->create();
    app(Wallet::class)->topUp($poor, 21_000); // < 25.000 harga penuh
    Discount::factory()->percentage(20)->create(['code' => 'HEMAT20']); // → 20.000

    $session = app(PlayFromWalletAction::class)->handle($poor->fresh(), $this->unit, $this->package, 'HEMAT20');

    expect($session->total_amount)->toBe(20_000)
        ->and($poor->fresh()->balance)->toBe(1_000);
});
