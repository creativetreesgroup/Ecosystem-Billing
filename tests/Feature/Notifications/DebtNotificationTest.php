<?php

use App\Domain\Billing\Actions\StartKioskOpenPlayAction;
use App\Domain\Billing\Actions\StopKioskOpenPlayAction;
use App\Domain\Devices\ControlDriver;
use App\Domain\Wallet\Events\CustomerWentIntoDebt;
use App\Domain\Wallet\Wallet;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/**
 * Utang Open Play memberi tahu OWNER saja (menagih saldo minus keputusan
 * pemilik), bukan kasir. Diuji lewat jalur nyata: main melebihi saldo → berhenti
 * → saldo minus → owner dapat notifikasi, kasir tidak.
 */
test('an open-play stop that draws credit notifies the owner, not the cashier', function () {
    $owner = User::factory()->owner()->create(['is_active' => true]);
    $kasir = User::factory()->create(['is_active' => true]);

    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $unit->unitType->update(['hourly_rate' => 6_000]); // Rp 100/menit

    $customer = Customer::factory()->create();
    app(Wallet::class)->topUp($customer, 5_000); // credit-eligible + saldo 5.000

    $session = app(StartKioskOpenPlayAction::class)->handle($customer->fresh(), $unit);
    $session->update(['started_at' => now()->subMinutes(60)]); // Rp 6.000 → saldo −1.000

    app(StopKioskOpenPlayAction::class)->handle($session);

    expect($customer->fresh()->balance)->toBe(-1_000)
        ->and($owner->fresh()->notifications()->count())->toBe(1)
        ->and($kasir->fresh()->notifications()->count())->toBe(0);

    expect($owner->fresh()->notifications()->first()->data['title'] ?? '')->toContain('minus');
});

/**
 * Berhenti dengan saldo cukup TIDAK memicu utang — tak ada notifikasi.
 */
test('an open-play stop covered by balance does not notify anyone', function () {
    $owner = User::factory()->owner()->create(['is_active' => true]);

    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $unit->unitType->update(['hourly_rate' => 6_000]);

    $customer = Customer::factory()->create();
    app(Wallet::class)->topUp($customer, 20_000);

    $session = app(StartKioskOpenPlayAction::class)->handle($customer->fresh(), $unit);
    $session->update(['started_at' => now()->subMinutes(30)]); // Rp 3.000 < saldo

    app(StopKioskOpenPlayAction::class)->handle($session);

    expect($customer->fresh()->balance)->toBe(17_000)
        ->and($owner->fresh()->notifications()->count())->toBe(0);
});

test('the debt notification listener is registered exactly once', function () {
    expect(Event::getListeners(CustomerWentIntoDebt::class))->toHaveCount(1);
});
