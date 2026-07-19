<?php

use App\Domain\Billing\PaymentMethod;
use App\Domain\Devices\ControlDriver;
use App\Domain\Sessions\Actions\CompleteSessionAction;
use App\Domain\Sessions\Actions\ExtendSessionAction;
use App\Domain\Sessions\Actions\StartSessionAction;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Models\Package;
use App\Models\Unit;
use App\Models\User;

/**
 * Skenario yang merugikan pelanggan secara nyata: sweep/expiry job sudah
 * membaca sesi sebagai "kedaluwarsa", LALU kasir menerima uang perpanjangan,
 * baru kemudian job-nya menutup sesi. Pelanggan sudah membayar tapi waktunya
 * langsung hangus. Fencing token harus dicek DI DALAM lock untuk mencegahnya.
 */
test('a session extended after the sweep snapshot is NOT closed by the stale token', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();
    $package = Package::factory()->for($unit->unitType)->create(['duration_minutes' => 60, 'price' => 30000]);

    $session = app(StartSessionAction::class)->handle(
        $unit, $kasir, SessionType::Package, package: $package, paymentMethod: PaymentMethod::Cash,
    );

    // Token seperti yang dipegang job/sweep sebelum perpanjangan terjadi.
    $staleToken = $session->expiry_token;

    app(ExtendSessionAction::class)->handle($session, addedMinutes: 60, amount: 20000, user: $kasir);

    app(CompleteSessionAction::class)->handle($session->fresh(), expectedExpiryToken: $staleToken);

    $session->refresh();
    expect($session->status)->toBe(SessionStatus::Active)
        ->and($session->ended_at)->toBeNull();
});

test('a completion carrying the current token still closes the session', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();
    $package = Package::factory()->for($unit->unitType)->create(['duration_minutes' => 60, 'price' => 30000]);

    $session = app(StartSessionAction::class)->handle(
        $unit, $kasir, SessionType::Package, package: $package, paymentMethod: PaymentMethod::Cash,
    );

    app(CompleteSessionAction::class)->handle($session, expectedExpiryToken: $session->expiry_token);

    expect($session->fresh()->status)->toBe(SessionStatus::Completed);
});

test('a manual close by the kasir passes no token and is never fenced out', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();

    $session = app(StartSessionAction::class)->handle($unit, $kasir, SessionType::Open);

    app(CompleteSessionAction::class)->handle($session, PaymentMethod::Cash);

    expect($session->fresh()->status)->toBe(SessionStatus::Completed);
});
