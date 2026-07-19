<?php

use App\Domain\Billing\PaymentMethod;
use App\Domain\Devices\ControlDriver;
use App\Domain\Sessions\Actions\CompleteSessionAction;
use App\Domain\Sessions\Actions\StartSessionAction;
use App\Domain\Sessions\Events\SessionEnded;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Models\DeviceAlert;
use App\Models\Package;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Illuminate\Support\Facades\Event;

test('completes an open-play session by calculating the amount from elapsed time', function () {
    $unitType = UnitType::factory()->create(['hourly_rate' => 6000]);
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual, 'unit_type_id' => $unitType->id]);
    $kasir = User::factory()->create();

    $session = app(StartSessionAction::class)->handle($unit, $kasir, SessionType::Open);
    $session->forceFill(['started_at' => now()->subMinutes(5)])->save();

    $completed = app(CompleteSessionAction::class)->handle($session, PaymentMethod::Cash);

    expect($completed->status)->toBe(SessionStatus::Completed)
        ->and($completed->total_amount)->toBe(500)
        ->and($completed->payment_method)->toBe(PaymentMethod::Cash)
        ->and($completed->ended_at)->not->toBeNull()
        ->and($completed->paid_at)->not->toBeNull();
});

test('completes a package session by summing base and extra amount, keeping the prepaid method', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();
    $package = Package::factory()->for($unit->unitType)->create(['price' => 10000]);

    $session = app(StartSessionAction::class)->handle(
        $unit,
        $kasir,
        SessionType::Package,
        package: $package,
        paymentMethod: PaymentMethod::Qris,
    );
    $session->update(['extra_amount' => 3000]);

    $completed = app(CompleteSessionAction::class)->handle($session);

    expect($completed->total_amount)->toBe(13000)
        ->and($completed->payment_method)->toBe(PaymentMethod::Qris);
});

test('rejects completing an open-play session without a payment method', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();
    $session = app(StartSessionAction::class)->handle($unit, $kasir, SessionType::Open);

    app(CompleteSessionAction::class)->handle($session);
})->throws(InvalidArgumentException::class);

test('completing a session twice is idempotent and does not change the amount', function () {
    $unitType = UnitType::factory()->create(['hourly_rate' => 6000]);
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual, 'unit_type_id' => $unitType->id]);
    $kasir = User::factory()->create();
    $session = app(StartSessionAction::class)->handle($unit, $kasir, SessionType::Open);
    $session->forceFill(['started_at' => now()->subMinutes(5)])->save();

    $first = app(CompleteSessionAction::class)->handle($session, PaymentMethod::Cash);
    $secondAttempt = $first->fresh();
    $second = app(CompleteSessionAction::class)->handle($secondAttempt, PaymentMethod::Transfer);

    expect($second->total_amount)->toBe($first->total_amount)
        ->and($second->payment_method)->toBe($first->payment_method)
        ->and($second->ended_at->equalTo($first->ended_at))->toBeTrue();
});

test('completing an already-voided session is a no-op, not an error', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();
    $session = app(StartSessionAction::class)->handle($unit, $kasir, SessionType::Open);
    $session->update(['status' => SessionStatus::Voided]);

    $result = app(CompleteSessionAction::class)->handle($session, PaymentMethod::Cash);

    expect($result->status)->toBe(SessionStatus::Voided);
});

test('broadcasts SessionEnded', function () {
    Event::fake([SessionEnded::class]);
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();
    $session = app(StartSessionAction::class)->handle($unit, $kasir, SessionType::Open);

    app(CompleteSessionAction::class)->handle($session, PaymentMethod::Cash);

    Event::assertDispatched(SessionEnded::class);
});

test('manual driver power-off raises a device alert on completion', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();
    $session = app(StartSessionAction::class)->handle($unit, $kasir, SessionType::Open);

    app(CompleteSessionAction::class)->handle($session, PaymentMethod::Cash);

    expect(DeviceAlert::where('unit_id', $unit->id)->exists())->toBeTrue();
});
