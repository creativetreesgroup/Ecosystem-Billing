<?php

use App\Domain\Billing\PaymentMethod;
use App\Domain\Devices\ControlDriver;
use App\Domain\Sessions\Actions\CompleteSessionAction;
use App\Domain\Sessions\Actions\ExtendSessionAction;
use App\Domain\Sessions\Actions\StartSessionAction;
use App\Domain\Sessions\Jobs\ExpireRentalSession;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Models\Package;
use App\Models\Unit;
use App\Models\User;

function startExpiringPackageSession(): array
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

    return [$session, $unit, $kasir];
}

test('a job with a matching token completes the still-active session', function () {
    [$session] = startExpiringPackageSession();

    (new ExpireRentalSession($session->id, $session->expiry_token))->handle(app(CompleteSessionAction::class));

    expect($session->fresh()->status)->toBe(SessionStatus::Completed);
});

test('a stale token from before an extension is a no-op', function () {
    [$session, , $kasir] = startExpiringPackageSession();
    $staleToken = $session->expiry_token;

    app(ExtendSessionAction::class)->handle($session, addedMinutes: 30, amount: 4000, user: $kasir);

    (new ExpireRentalSession($session->id, $staleToken))->handle(app(CompleteSessionAction::class));

    expect($session->fresh()->status)->toBe(SessionStatus::Active);
});

test('a job for an already-completed session is a no-op', function () {
    [$session] = startExpiringPackageSession();
    $token = $session->expiry_token;
    app(CompleteSessionAction::class)->handle($session);
    $completedAt = $session->fresh()->ended_at;

    (new ExpireRentalSession($session->id, $token))->handle(app(CompleteSessionAction::class));

    expect($session->fresh()->ended_at->equalTo($completedAt))->toBeTrue();
});
