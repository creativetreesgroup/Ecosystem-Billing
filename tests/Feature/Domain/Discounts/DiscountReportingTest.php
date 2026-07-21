<?php

use App\Domain\Billing\SalesSummary;
use App\Domain\Devices\ControlDriver;
use App\Domain\Sessions\Actions\CompleteSessionAction;
use App\Domain\Wallet\Actions\PlayFromWalletAction;
use App\Domain\Wallet\Wallet;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Package;
use App\Models\Unit;
use App\Models\User;

/**
 * Owner harus bisa MELIHAT berapa pendapatan yang direlakan demi diskon —
 * kalau tidak, promo tak bisa dievaluasi. totalDiscount() dari dataset sesi yang
 * sama dengan revenue, jadi selalu selaras.
 */
test('the sales summary reports total discount given on revenue sessions', function () {
    User::factory()->owner()->create();
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $package = Package::factory()->for($unit->unitType)->create(['price' => 25_000, 'duration_minutes' => 60]);
    $customer = Customer::factory()->create();
    app(Wallet::class)->topUp($customer, 100_000);
    Discount::factory()->percentage(20)->create(['code' => 'HEMAT20']);

    $session = app(PlayFromWalletAction::class)->handle($customer->fresh(), $unit, $package, 'HEMAT20');
    app(CompleteSessionAction::class)->handle($session->fresh());

    $today = now(SalesSummary::timezone())->toDateString();
    $summary = new SalesSummary($today, $today);

    expect($summary->totalDiscount())->toBe(5_000)      // potongan 20% dari 25.000
        ->and($summary->totalRevenue())->toBe(20_000);  // yang benar-benar masuk
});

test('a period without discounts reports zero', function () {
    User::factory()->owner()->create();
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $package = Package::factory()->for($unit->unitType)->create(['price' => 25_000, 'duration_minutes' => 60]);
    $customer = Customer::factory()->create();
    app(Wallet::class)->topUp($customer, 100_000);

    $session = app(PlayFromWalletAction::class)->handle($customer->fresh(), $unit, $package);
    app(CompleteSessionAction::class)->handle($session->fresh());

    $today = now(SalesSummary::timezone())->toDateString();
    expect((new SalesSummary($today, $today))->totalDiscount())->toBe(0);
});
