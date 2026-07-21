<?php

use App\Domain\Billing\Actions\StartKioskOpenPlayAction;
use App\Domain\Billing\Actions\StopKioskOpenPlayAction;
use App\Domain\Devices\ControlDriver;
use App\Domain\Discounts\DiscountTarget;
use App\Domain\Discounts\Exceptions\DiscountNotApplicableException;
use App\Domain\Wallet\Wallet;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\DiscountRedemption;
use App\Models\Unit;
use App\Models\User;

beforeEach(function () {
    User::factory()->owner()->create(); // operator kios
    $this->unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $this->unit->unitType->update(['hourly_rate' => 6_000]); // Rp 100/menit
    $this->wallet = app(Wallet::class);
});

/**
 * Voucher Open Play memotong TAGIHAN AKHIR (dihitung saat berhenti), bukan harga
 * di muka — karena Open Play tak punya harga sampai berhenti.
 */
test('an open play voucher discounts the final bill at stop', function () {
    Discount::factory()->percentage(20)->forTargets([DiscountTarget::OpenPlay])->create(['code' => 'MAIN20']);
    $customer = Customer::factory()->create();
    $this->wallet->topUp($customer, 20_000);

    $session = app(StartKioskOpenPlayAction::class)->handle($customer->fresh(), $this->unit, 'MAIN20');
    expect($session->voucher_code)->toBe('MAIN20');

    $session->update(['started_at' => now()->subMinutes(60)]); // tagihan mentah Rp 6.000
    $done = app(StopKioskOpenPlayAction::class)->handle($session);

    // 6.000 − 20% = 4.800; potongan 1.200.
    expect($done->total_amount)->toBe(4_800)
        ->and($done->discount_amount)->toBe(1_200)
        ->and($customer->fresh()->balance)->toBe(15_200) // 20.000 − 4.800
        ->and($done->payments()->sole()->amount)->toBe(4_800);

    expect(DiscountRedemption::where('rental_session_id', $session->id)->first()->amount)->toBe(1_200);
});

/**
 * Kode salah ditolak SEBELUM main, bukan setelah — supaya pelanggan tak terlanjur
 * bermain dengan voucher yang ternyata tak berlaku.
 */
test('a bad open play voucher is rejected before playing', function () {
    $customer = Customer::factory()->create();
    $this->wallet->topUp($customer, 20_000);

    expect(fn () => app(StartKioskOpenPlayAction::class)->handle($customer->fresh(), $this->unit, 'NGACO'))
        ->toThrow(DiscountNotApplicableException::class);

    expect($this->unit->fresh()->activeSession)->toBeNull();
});

/**
 * Tanpa voucher, Open Play ditagih penuh seperti biasa (tak ada regresi).
 */
test('open play without a voucher still bills the full amount', function () {
    $customer = Customer::factory()->create();
    $this->wallet->topUp($customer, 20_000);

    $session = app(StartKioskOpenPlayAction::class)->handle($customer->fresh(), $this->unit);
    $session->update(['started_at' => now()->subMinutes(60)]);
    $done = app(StopKioskOpenPlayAction::class)->handle($session);

    expect($done->total_amount)->toBe(6_000)
        ->and($done->discount_amount)->toBe(0);
});
