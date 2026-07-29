<?php

use App\Domain\Discounts\DiscountEngine;
use App\Domain\Discounts\DiscountTarget;
use App\Domain\Discounts\Exceptions\DiscountNotApplicableException;
use App\Models\Customer;
use App\Models\Discount;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->engine = app(DiscountEngine::class);
    $this->customer = Customer::factory()->create();
});

test('a percentage voucher computes a floored discount and final amount', function () {
    Discount::factory()->percentage(20)->create(['code' => 'HEMAT20']);

    $result = $this->engine->preview('HEMAT20', DiscountTarget::Package, 25_000, $this->customer);

    expect($result->discount)->toBe(5_000)     // 20% dari 25.000
        ->and($result->finalAmount)->toBe(20_000);
});

test('a fixed voucher never discounts more than the price', function () {
    Discount::factory()->fixed(30_000)->create(['code' => 'POTONG30']);

    $result = $this->engine->preview('POTONG30', DiscountTarget::Package, 25_000, $this->customer);

    // Dijepit ke harga: potongan 30rb pada harga 25rb → 25rb, harga akhir 0.
    expect($result->discount)->toBe(25_000)
        ->and($result->finalAmount)->toBe(0);
});

test('the code match ignores case and surrounding spaces', function () {
    Discount::factory()->percentage(10)->create(['code' => 'HEMAT10']);

    expect($this->engine->preview('  hemat10 ', DiscountTarget::Package, 10_000, $this->customer)->discount)->toBe(1_000);
});

test('an unknown code is refused', function () {
    expect(fn () => $this->engine->preview('NGACO', DiscountTarget::Package, 10_000, $this->customer))
        ->toThrow(DiscountNotApplicableException::class);
});

test('a voucher for another target is refused', function () {
    Discount::factory()->percentage(10)->forTargets([DiscountTarget::TopUp])->create(['code' => 'TOPUPONLY']);

    expect(fn () => $this->engine->preview('TOPUPONLY', DiscountTarget::Package, 10_000, $this->customer))
        ->toThrow(DiscountNotApplicableException::class);
});

test('an inactive or out-of-window voucher is refused', function () {
    Discount::factory()->percentage(10)->create(['code' => 'MATI', 'is_active' => false]);
    Discount::factory()->percentage(10)->create(['code' => 'KADALUARSA', 'ends_at' => now()->subDay()]);
    Discount::factory()->percentage(10)->create(['code' => 'BELUM', 'starts_at' => now()->addDay()]);

    foreach (['MATI', 'KADALUARSA', 'BELUM'] as $code) {
        expect(fn () => $this->engine->preview($code, DiscountTarget::Package, 10_000, $this->customer))
            ->toThrow(DiscountNotApplicableException::class);
    }
});

test('a voucher below its minimum amount is refused', function () {
    Discount::factory()->percentage(10)->create(['code' => 'MIN50', 'min_amount' => 50_000]);

    expect(fn () => $this->engine->preview('MIN50', DiscountTarget::Package, 25_000, $this->customer))
        ->toThrow(DiscountNotApplicableException::class);
});

test('redeeming records a redemption and returns the discount amount', function () {
    $discount = Discount::factory()->percentage(20)->create(['code' => 'HEMAT20']);

    $redemption = DB::transaction(fn () => $this->engine->redeem(
        'HEMAT20', DiscountTarget::Package, 25_000, $this->customer, ['customer_id' => $this->customer->id],
    ));

    expect($redemption->amount)->toBe(5_000)
        ->and($discount->redemptions()->count())->toBe(1);
});

test('the global quota cannot be exceeded', function () {
    Discount::factory()->percentage(10)->create(['code' => 'SEKALI', 'max_uses' => 1]);

    DB::transaction(fn () => $this->engine->redeem('SEKALI', DiscountTarget::Package, 10_000, $this->customer, ['customer_id' => $this->customer->id]));

    expect(fn () => DB::transaction(fn () => $this->engine->redeem('SEKALI', DiscountTarget::Package, 10_000, $this->customer, ['customer_id' => $this->customer->id])))
        ->toThrow(DiscountNotApplicableException::class);
});

test('the per-customer quota cannot be exceeded', function () {
    Discount::factory()->percentage(10)->create(['code' => 'SEKALIORANG', 'max_uses_per_customer' => 1]);

    DB::transaction(fn () => $this->engine->redeem('SEKALIORANG', DiscountTarget::Package, 10_000, $this->customer, ['customer_id' => $this->customer->id]));

    // Pelanggan yang sama tak bisa lagi; pelanggan lain masih bisa.
    expect(fn () => DB::transaction(fn () => $this->engine->redeem('SEKALIORANG', DiscountTarget::Package, 10_000, $this->customer, ['customer_id' => $this->customer->id])))
        ->toThrow(DiscountNotApplicableException::class);

    $other = Customer::factory()->create();
    $ok = DB::transaction(fn () => $this->engine->redeem('SEKALIORANG', DiscountTarget::Package, 10_000, $other, ['customer_id' => $other->id]));
    expect($ok->amount)->toBe(1_000);
});
