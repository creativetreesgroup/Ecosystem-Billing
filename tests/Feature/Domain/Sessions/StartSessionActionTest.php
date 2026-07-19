<?php

use App\Domain\Billing\PaymentMethod;
use App\Domain\Devices\ControlDriver;
use App\Domain\Sessions\Actions\StartSessionAction;
use App\Domain\Sessions\Events\SessionStarted;
use App\Domain\Sessions\Exceptions\UnitAlreadyActiveException;
use App\Domain\Sessions\Jobs\ExpireRentalSession;
use App\Domain\Sessions\Jobs\WarnSessionEnding;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Models\Package;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

function startAction(): StartSessionAction
{
    return app(StartSessionAction::class);
}

test('opens an open-play session with no end time and zero base amount', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();

    $session = startAction()->handle($unit, $kasir, SessionType::Open, customerName: 'Budi');

    expect($session->status)->toBe(SessionStatus::Active)
        ->and($session->ends_at)->toBeNull()
        ->and($session->base_amount)->toBe(0)
        ->and($session->customer_name)->toBe('Budi')
        ->and($session->expiry_token)->not->toBeEmpty();
});

test('opens a package session with computed end time and prepaid amount', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();
    $package = Package::factory()->for($unit->unitType)->create(['duration_minutes' => 120, 'price' => 16000]);

    $session = startAction()->handle(
        $unit,
        $kasir,
        SessionType::Package,
        package: $package,
        paymentMethod: PaymentMethod::Cash,
    );

    expect($session->status)->toBe(SessionStatus::Active)
        ->and($session->ends_at->equalTo($session->started_at->copy()->addMinutes(120)))->toBeTrue()
        ->and($session->base_amount)->toBe(16000)
        ->and($session->payment_method)->toBe(PaymentMethod::Cash)
        ->and($session->paid_at)->not->toBeNull();
});

test('rejects starting a session on a unit that already has an active session', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();

    startAction()->handle($unit, $kasir, SessionType::Open);

    startAction()->handle($unit, $kasir, SessionType::Open);
})->throws(UnitAlreadyActiveException::class);

test('rejects a package session without a package', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();

    startAction()->handle($unit, $kasir, SessionType::Package, paymentMethod: PaymentMethod::Cash);
})->throws(InvalidArgumentException::class);

test('rejects a package session without a payment method since packages are prepaid', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();
    $package = Package::factory()->for($unit->unitType)->create();

    startAction()->handle($unit, $kasir, SessionType::Package, package: $package);
})->throws(InvalidArgumentException::class);

test('broadcasts SessionStarted', function () {
    Event::fake([SessionStarted::class]);

    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();

    $session = startAction()->handle($unit, $kasir, SessionType::Open);

    Event::assertDispatched(SessionStarted::class, fn (SessionStarted $e) => $e->sessionId === $session->id);
});

test('only package sessions dispatch the expiry job, since open play has no fixed end time', function () {
    Bus::fake();

    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();

    startAction()->handle($unit, $kasir, SessionType::Open);

    Bus::assertNotDispatched(ExpireRentalSession::class);

    $package = Package::factory()->for($unit->unitType)->create();
    $unit2 = Unit::factory()->create(['control_driver' => ControlDriver::Manual, 'unit_type_id' => $unit->unit_type_id]);

    startAction()->handle($unit2, $kasir, SessionType::Package, package: $package, paymentMethod: PaymentMethod::Cash);

    Bus::assertDispatched(ExpireRentalSession::class);
    Bus::assertDispatched(WarnSessionEnding::class);
});
