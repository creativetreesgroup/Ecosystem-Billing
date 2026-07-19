<?php

use App\Domain\Billing\PaymentMethod;
use App\Domain\Devices\ControlDriver;
use App\Domain\Sessions\Actions\CompleteSessionAction;
use App\Domain\Sessions\Actions\StartSessionAction;
use App\Domain\Sessions\Actions\VoidSessionAction;
use App\Domain\Sessions\Exceptions\IllegalSessionTransitionException;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
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

test('voiding frees the unit for a new active session', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();
    $owner = User::factory()->owner()->create();
    $session = app(StartSessionAction::class)->handle($unit, $kasir, SessionType::Open);

    app(VoidSessionAction::class)->handle($session, $owner, 'Salah buka unit');

    $newSession = app(StartSessionAction::class)->handle($unit->fresh(), $kasir, SessionType::Open);

    expect($newSession->status)->toBe(SessionStatus::Active);
});
