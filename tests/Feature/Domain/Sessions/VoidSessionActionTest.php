<?php

use App\Domain\Billing\PaymentMethod;
use App\Domain\Devices\ControlDriver;
use App\Domain\Sessions\Actions\CompleteSessionAction;
use App\Domain\Sessions\Actions\StartSessionAction;
use App\Domain\Sessions\Actions\VoidSessionAction;
use App\Domain\Sessions\Exceptions\IllegalSessionTransitionException;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Domain\Wallet\Actions\PlayFromWalletAction;
use App\Domain\Wallet\Wallet;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Unit;
use App\Models\User;

test('voids an active session and stops it', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();
    $owner = User::factory()->owner()->create();
    $session = app(StartSessionAction::class)->handle($unit, $kasir, SessionType::Open);

    $voided = app(VoidSessionAction::class)->handle($session, $owner, 'Salah buka unit');

    expect($voided->status)->toBe(SessionStatus::Voided)
        ->and($voided->voided_by)->toBe($owner->id)
        ->and($voided->void_reason)->toBe('Salah buka unit')
        ->and($voided->ended_at)->not->toBeNull();
});

test('voids a completed session for correction after the fact', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();
    $owner = User::factory()->owner()->create();
    $session = app(StartSessionAction::class)->handle($unit, $kasir, SessionType::Open);
    $completed = app(CompleteSessionAction::class)->handle($session, PaymentMethod::Cash);
    $originalEndedAt = $completed->ended_at;

    $voided = app(VoidSessionAction::class)->handle($completed, $owner, 'Salah catat pembayaran');

    expect($voided->status)->toBe(SessionStatus::Voided)
        ->and($voided->ended_at->equalTo($originalEndedAt))->toBeTrue();
});

test('rejects voiding without a reason', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();
    $owner = User::factory()->owner()->create();
    $session = app(StartSessionAction::class)->handle($unit, $kasir, SessionType::Open);

    app(VoidSessionAction::class)->handle($session, $owner, '   ');
})->throws(InvalidArgumentException::class);

test('rejects voiding an already-voided session', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();
    $owner = User::factory()->owner()->create();
    $session = app(StartSessionAction::class)->handle($unit, $kasir, SessionType::Open);
    app(VoidSessionAction::class)->handle($session, $owner, 'Pertama');

    app(VoidSessionAction::class)->handle($session->fresh(), $owner, 'Kedua');
})->throws(IllegalSessionTransitionException::class);

/**
 * Void sesi yang dibayar dari DOMPET wajib mengembalikan saldo: tanpa refund,
 * pelanggan tetap terpotong untuk sesi yang justru dibatalkan outlet. Diuji lewat
 * PlayFromWallet (memotong di awal, tanpa baris Payment) — jalur yang paling
 * mudah terlewat.
 */
test('voiding a wallet-paid session refunds the exact amount charged', function () {
    User::factory()->owner()->create(); // operator kios untuk PlayFromWalletAction
    $owner = User::factory()->owner()->create();
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $package = Package::factory()->for($unit->unitType)->create(['price' => 15_000]);

    $customer = Customer::factory()->create();
    app(Wallet::class)->topUp($customer, 50_000);

    $session = app(PlayFromWalletAction::class)->handle($customer->fresh(), $unit, $package);
    expect($customer->fresh()->balance)->toBe(35_000); // 50k − 15k

    app(VoidSessionAction::class)->handle($session, $owner, 'Unit rusak di tengah main');

    // Saldo utuh kembali, dan buku besar sesi ini net nol (tarik + refund).
    expect($customer->fresh()->balance)->toBe(50_000)
        ->and($customer->fresh()->ledgerBalance())->toBe(50_000);
});

/**
 * Sebaliknya: void sesi yang TIDAK menyentuh dompet (mis. tunai) tidak membuat
 * refund dompet — uangnya di luar sistem.
 */
test('voiding a non-wallet session does not touch any wallet', function () {
    $kasir = User::factory()->create();
    $owner = User::factory()->owner()->create();
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $session = app(StartSessionAction::class)->handle($unit, $kasir, SessionType::Open);
    $completed = app(CompleteSessionAction::class)->handle($session, PaymentMethod::Cash);

    app(VoidSessionAction::class)->handle($completed, $owner, 'Salah catat');

    // Tak ada pelanggan/dompet yang terlibat — tidak error, tidak ada refund.
    expect($completed->fresh()->status)->toBe(SessionStatus::Voided);
});

test('voiding frees the unit for a new active session', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();
    $owner = User::factory()->owner()->create();
    $session = app(StartSessionAction::class)->handle($unit, $kasir, SessionType::Open);

    app(VoidSessionAction::class)->handle($session, $owner, 'Salah buka unit');

    $newSession = app(StartSessionAction::class)->handle($unit->fresh(), $kasir, SessionType::Open);

    expect($newSession->status)->toBe(SessionStatus::Active);
});
