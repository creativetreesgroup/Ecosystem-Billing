<?php

use App\Domain\Billing\PaymentMethod;
use App\Domain\Devices\ControlDriver;
use App\Domain\Sessions\Actions\CompleteSessionAction;
use App\Domain\Sessions\Actions\ExtendSessionAction;
use App\Domain\Sessions\Actions\StartSessionAction;
use App\Domain\Sessions\Events\SessionExtended;
use App\Domain\Sessions\Exceptions\IllegalSessionTransitionException;
use App\Domain\Sessions\Jobs\ExpireRentalSessionJob;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Domain\Wallet\Actions\PlayFromWalletAction;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Wallet;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

function startPackageSession(?Unit $unit = null): array
{
    $unit ??= Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();
    $package = Package::factory()->for($unit->unitType)->create(['duration_minutes' => 60, 'price' => 8000]);

    $session = app(StartSessionAction::class)->handle(
        $unit,
        $kasir,
        SessionType::Package,
        package: $package,
        paymentMethod: PaymentMethod::Cash,
    );

    return [$session, $unit, $kasir];
}

test('extending a package session adds minutes and amount, and logs an extension row', function () {
    [$session, , $kasir] = startPackageSession();
    $originalEndsAt = $session->ends_at->copy();

    $extended = app(ExtendSessionAction::class)->handle($session, addedMinutes: 30, amount: 4000, user: $kasir);

    expect($extended->ends_at->equalTo($originalEndsAt->copy()->addMinutes(30)))->toBeTrue()
        ->and($extended->extra_amount)->toBe(4000)
        ->and($extended->extensions()->count())->toBe(1);
});

/**
 * Sesi yang dibayar dari SALDO: perpanjangan WAJIB menarik saldo juga — kalau
 * tidak, extra_amount menaikkan pendapatan Wallet tapi dompet tak terpotong
 * (pendapatan hantu + pelanggan main gratis). Rekonsiliasi: saldo, total, dan
 * Payment penyelesaian harus cocok.
 */
test('extending a wallet-paid session debits the wallet for the extension', function () {
    User::factory()->owner()->create(); // operator kios
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $package = Package::factory()->for($unit->unitType)->create(['duration_minutes' => 60, 'price' => 20_000]);
    $customer = Customer::factory()->create();
    app(Wallet::class)->topUp($customer, 50_000);

    $session = app(PlayFromWalletAction::class)->handle($customer->fresh(), $unit, $package);
    expect($customer->fresh()->balance)->toBe(30_000); // 50rb − 20rb paket

    app(ExtendSessionAction::class)->handle($session, addedMinutes: 30, amount: 10_000, user: User::factory()->create());

    // Perpanjangan 10rb ditarik dari saldo → 20rb; buku besar cocok.
    expect($customer->fresh()->balance)->toBe(20_000)
        ->and($customer->fresh()->ledgerBalance())->toBe(20_000);

    // Total & pembayaran penyelesaian = paket + perpanjangan, konsisten dengan debit.
    $done = app(CompleteSessionAction::class)->handle($session->fresh());
    expect($done->total_amount)->toBe(30_000)
        ->and($done->payments()->sole()->amount)->toBe(30_000);
});

/**
 * Saldo tak cukup untuk perpanjangan → SELURUH perpanjangan batal (atomik):
 * waktu, extra_amount, saldo tak berubah, tak ada baris extension.
 */
test('extending a wallet session with insufficient balance rolls back entirely', function () {
    User::factory()->owner()->create();
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $package = Package::factory()->for($unit->unitType)->create(['duration_minutes' => 60, 'price' => 20_000]);
    $customer = Customer::factory()->create();
    app(Wallet::class)->topUp($customer, 22_000);

    $session = app(PlayFromWalletAction::class)->handle($customer->fresh(), $unit, $package);
    $endsAtBefore = $session->fresh()->ends_at->copy();

    expect(fn () => app(ExtendSessionAction::class)->handle($session, addedMinutes: 30, amount: 50_000, user: User::factory()->create()))
        ->toThrow(InsufficientBalanceException::class);

    $fresh = $session->fresh();
    expect($fresh->ends_at->equalTo($endsAtBefore))->toBeTrue()
        ->and($fresh->extra_amount)->toBe(0)
        ->and($fresh->extensions()->count())->toBe(0)
        ->and($customer->fresh()->balance)->toBe(2_000); // 22rb − 20rb paket, tak tersentuh gagal-extend
});

test('extending regenerates the expiry token so the old delayed job becomes stale', function () {
    [$session, , $kasir] = startPackageSession();
    $originalToken = $session->expiry_token;

    $extended = app(ExtendSessionAction::class)->handle($session, addedMinutes: 15, amount: 2000, user: $kasir);

    expect($extended->expiry_token)->not->toBe($originalToken);
});

test('extending dispatches a fresh expiry job with the new token', function () {
    Bus::fake();
    [$session, , $kasir] = startPackageSession();

    $extended = app(ExtendSessionAction::class)->handle($session, addedMinutes: 15, amount: 2000, user: $kasir);

    Bus::assertDispatched(ExpireRentalSessionJob::class, fn ($job) => $job->expiryToken === $extended->expiry_token);
});

test('rejects extending an open-play session', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();
    $session = app(StartSessionAction::class)->handle($unit, $kasir, SessionType::Open);

    app(ExtendSessionAction::class)->handle($session, addedMinutes: 30, amount: 4000, user: $kasir);
})->throws(IllegalSessionTransitionException::class);

test('rejects extending a session that is not active', function () {
    [$session, , $kasir] = startPackageSession();
    $session->update(['status' => SessionStatus::Completed]);

    app(ExtendSessionAction::class)->handle($session, addedMinutes: 30, amount: 4000, user: $kasir);
})->throws(IllegalSessionTransitionException::class);

test('broadcasts SessionExtended', function () {
    Event::fake([SessionExtended::class]);
    [$session, , $kasir] = startPackageSession();

    app(ExtendSessionAction::class)->handle($session, addedMinutes: 30, amount: 4000, user: $kasir);

    Event::assertDispatched(SessionExtended::class);
});
