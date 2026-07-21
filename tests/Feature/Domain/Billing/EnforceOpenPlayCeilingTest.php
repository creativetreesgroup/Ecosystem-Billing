<?php

use App\Domain\Billing\Actions\StartKioskOpenPlayAction;
use App\Domain\Devices\ControlDriver;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Wallet\Wallet;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;

/**
 * Jaring pengaman untuk HP yang ditutup: saat auto-stop sisi klien tak bisa
 * jalan, command server yang menghentikan sesi Open Play kios di plafon kredit —
 * supaya tagihan tak melewati −50k jadi kerugian outlet.
 */
beforeEach(function () {
    User::factory()->owner()->create(); // operator kios (StartKioskOpenPlayAction)
    $this->unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $this->unit->unitType->update(['hourly_rate' => 6_000]); // Rp 100/menit
});

test('it auto-stops a kiosk Open Play session that reaches the credit ceiling', function () {
    $customer = Customer::factory()->create();
    app(Wallet::class)->topUp($customer, 10_000);

    $session = app(StartKioskOpenPlayAction::class)->handle($customer->fresh(), $this->unit);
    // 600 menit × Rp 100 = Rp 60.000 = saldo 10rb + plafon 50rb → tepat di plafon.
    $session->update(['started_at' => now()->subMinutes(600)]);

    $this->artisan('openplay:enforce-ceiling')->assertSuccessful();

    expect($session->fresh()->status)->toBe(SessionStatus::Completed)
        ->and($customer->fresh()->balance)->toBe(-50_000)
        ->and($customer->fresh()->ledgerBalance())->toBe(-50_000);
});

test('it leaves a session still below the ceiling running and unbilled', function () {
    $customer = Customer::factory()->create();
    app(Wallet::class)->topUp($customer, 10_000);

    $session = app(StartKioskOpenPlayAction::class)->handle($customer->fresh(), $this->unit);
    $session->update(['started_at' => now()->subMinutes(100)]); // Rp 10.000, jauh di bawah plafon

    $this->artisan('openplay:enforce-ceiling')->assertSuccessful();

    expect($session->fresh()->status)->toBe(SessionStatus::Active)
        ->and($customer->fresh()->balance)->toBe(10_000); // Open Play belum ditagih sampai berhenti
});
