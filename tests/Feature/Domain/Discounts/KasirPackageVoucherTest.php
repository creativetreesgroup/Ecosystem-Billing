<?php

use App\Domain\Billing\PaymentMethod;
use App\Domain\Devices\ControlDriver;
use App\Domain\Discounts\Exceptions\DiscountNotApplicableException;
use App\Domain\Sessions\Actions\CompleteSessionAction;
use App\Domain\Sessions\Actions\StartSessionAction;
use App\Domain\Sessions\SessionType;
use App\Models\Discount;
use App\Models\DiscountRedemption;
use App\Models\Package;
use App\Models\Unit;
use App\Models\User;

beforeEach(function () {
    $this->kasir = User::factory()->create();
    $this->unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $this->package = Package::factory()->for($this->unit->unitType)->create(['price' => 25_000, 'duration_minutes' => 60]);
});

/**
 * Kasir bisa menerapkan voucher paket. Sesi kasir tak punya akun, jadi
 * redemption-nya tanpa customer_id (kuota total tetap menjaga), dan potongannya
 * bertahan sampai penyelesaian.
 */
test('a cashier applies a package voucher and the discount is recorded and billed', function () {
    Discount::factory()->percentage(20)->create(['code' => 'HEMAT20']);

    $session = app(StartSessionAction::class)->handle(
        $this->unit,
        $this->kasir,
        SessionType::Package,
        package: $this->package,
        paymentMethod: PaymentMethod::Cash,
        voucherCode: 'HEMAT20',
    );

    expect($session->base_amount)->toBe(25_000)
        ->and($session->discount_amount)->toBe(5_000);

    $redemption = DiscountRedemption::where('rental_session_id', $session->id)->first();
    expect($redemption->amount)->toBe(5_000)
        ->and($redemption->customer_id)->toBeNull();

    // Penyelesaian menagih harga terpotong, bukan harga penuh.
    $completed = app(CompleteSessionAction::class)->handle($session->fresh());
    expect($completed->total_amount)->toBe(20_000)
        ->and($completed->payments()->sole()->amount)->toBe(20_000);
});

test('a bad voucher at the cashier is rejected, no session state left half-done', function () {
    expect(fn () => app(StartSessionAction::class)->handle(
        $this->unit,
        $this->kasir,
        SessionType::Package,
        package: $this->package,
        paymentMethod: PaymentMethod::Cash,
        voucherCode: 'NGACO',
    ))->toThrow(DiscountNotApplicableException::class);

    expect($this->unit->fresh()->activeSession)->toBeNull()
        ->and(DiscountRedemption::count())->toBe(0);
});
