<?php

use App\Domain\Billing\PaymentMethod;
use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\DeviceManager;
use App\Domain\Sessions\Actions\ExtendSessionAction;
use App\Domain\Sessions\Actions\StartSessionAction;
use App\Domain\Sessions\Events\SessionEnding;
use App\Domain\Sessions\Jobs\WarnSessionEnding;
use App\Domain\Sessions\SessionType;
use App\Models\Package;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Event;

test('a job with a matching token broadcasts the warning', function () {
    Event::fake([SessionEnding::class]);

    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();
    $package = Package::factory()->for($unit->unitType)->create();
    $session = app(StartSessionAction::class)->handle($unit, $kasir, SessionType::Package, package: $package, paymentMethod: PaymentMethod::Cash);

    (new WarnSessionEnding($session->id, $session->expiry_token))->handle(app(DeviceManager::class));

    Event::assertDispatched(SessionEnding::class, fn (SessionEnding $e) => $e->sessionId === $session->id);
});

test('a stale token does not broadcast a warning', function () {
    Event::fake([SessionEnding::class]);

    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $kasir = User::factory()->create();
    $package = Package::factory()->for($unit->unitType)->create();
    $session = app(StartSessionAction::class)->handle($unit, $kasir, SessionType::Package, package: $package, paymentMethod: PaymentMethod::Cash);
    $staleToken = $session->expiry_token;

    app(ExtendSessionAction::class)->handle($session, addedMinutes: 30, amount: 4000, user: $kasir);

    (new WarnSessionEnding($session->id, $staleToken))->handle(app(DeviceManager::class));

    Event::assertNotDispatched(SessionEnding::class);
});
