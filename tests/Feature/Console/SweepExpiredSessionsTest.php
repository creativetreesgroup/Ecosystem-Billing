<?php

use App\Domain\Billing\PaymentMethod;
use App\Domain\Devices\ControlDriver;
use App\Domain\Sessions\Actions\StartSessionAction;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Models\Package;
use App\Models\RentalSession;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;

function startPackageSessionEndingAt(Carbon $endsAt): RentalSession
{
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();
    $package = Package::factory()->for($unit->unitType)->create();

    $session = app(StartSessionAction::class)->handle(
        $unit,
        $kasir,
        SessionType::Package,
        package: $package,
        paymentMethod: PaymentMethod::Cash,
    );

    $session->forceFill(['ends_at' => $endsAt])->save();

    return $session->fresh();
}

test('sweeps a package session that expired more than 30 seconds ago', function () {
    $session = startPackageSessionEndingAt(now()->subMinute());

    $this->artisan('sessions:sweep-expired')->assertSuccessful();

    expect($session->fresh()->status)->toBe(SessionStatus::Completed);
});

test('does not touch a session still within the 30-second grace period', function () {
    $session = startPackageSessionEndingAt(now()->subSeconds(10));

    $this->artisan('sessions:sweep-expired')->assertSuccessful();

    expect($session->fresh()->status)->toBe(SessionStatus::Active);
});

test('never touches open-play sessions since they have no fixed end time', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();
    $session = app(StartSessionAction::class)->handle($unit, $kasir, SessionType::Open);

    $this->artisan('sessions:sweep-expired')->assertSuccessful();

    expect($session->fresh()->status)->toBe(SessionStatus::Active);
});
