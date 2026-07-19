<?php

use App\Domain\Billing\PaymentMethod;
use App\Domain\Devices\ControlDriver;
use App\Domain\Sessions\Actions\ExtendSessionAction;
use App\Domain\Sessions\Actions\StartSessionAction;
use App\Domain\Sessions\Events\SessionExtended;
use App\Domain\Sessions\Exceptions\IllegalSessionTransitionException;
use App\Domain\Sessions\Jobs\ExpireRentalSession;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
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

    Bus::assertDispatched(ExpireRentalSession::class, fn ($job) => $job->expiryToken === $extended->expiry_token);
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
