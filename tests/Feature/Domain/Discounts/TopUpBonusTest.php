<?php

use App\Domain\Billing\Actions\ApplySettledPaymentAction;
use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Discounts\DiscountTarget;
use App\Domain\Discounts\Exceptions\DiscountNotApplicableException;
use App\Domain\Wallet\Actions\OpenTopUpAction;
use App\Domain\Wallet\Actions\SettleCashTopUpAction;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\DiscountRedemption;
use App\Models\User;

/**
 * Voucher isi saldo memberi BONUS saldo (bayar penuh, saldo bertambah lebih),
 * dan bonusnya HANYA keluar saat pembayaran benar-benar lunas.
 */
test('a top-up voucher credits a bonus only when the payment settles', function () {
    Discount::factory()->percentage(20)->forTargets([DiscountTarget::TopUp])->create(['code' => 'BONUS20']);
    $customer = Customer::factory()->create();

    ['payment' => $payment] = app(OpenTopUpAction::class)->handle($customer, 100_000, PaymentMethod::Transfer, 'BONUS20');
    expect($payment->voucher_code)->toBe('BONUS20')
        ->and($customer->fresh()->balance)->toBe(0); // masih pending, belum ada bonus

    $payment->update(['status' => PaymentStatus::Paid]);
    app(ApplySettledPaymentAction::class)->handle($payment->fresh());

    expect($customer->fresh()->balance)->toBe(120_000) // 100k + bonus 20%
        ->and(DiscountRedemption::where('payment_id', $payment->id)->first()->amount)->toBe(20_000);
});

test('settling a top-up twice credits the bonus only once', function () {
    Discount::factory()->percentage(20)->forTargets([DiscountTarget::TopUp])->create(['code' => 'BONUS20']);
    $customer = Customer::factory()->create();
    ['payment' => $payment] = app(OpenTopUpAction::class)->handle($customer, 100_000, PaymentMethod::Transfer, 'BONUS20');
    $payment->update(['status' => PaymentStatus::Paid]);

    app(ApplySettledPaymentAction::class)->handle($payment->fresh());
    app(ApplySettledPaymentAction::class)->handle($payment->fresh());

    expect($customer->fresh()->balance)->toBe(120_000)
        ->and(DiscountRedemption::where('payment_id', $payment->id)->count())->toBe(1);
});

test('a cash top-up voucher credits the bonus immediately', function () {
    Discount::factory()->fixed(10_000)->forTargets([DiscountTarget::TopUp])->create(['code' => 'CASH10']);
    $customer = Customer::factory()->create();

    app(SettleCashTopUpAction::class)->handle($customer, 50_000, User::factory()->create(), 'CASH10');

    expect($customer->fresh()->balance)->toBe(60_000); // 50k + bonus 10k
});

test('a bad top-up voucher is rejected at checkout, no payment created', function () {
    $customer = Customer::factory()->create();

    expect(fn () => app(OpenTopUpAction::class)->handle($customer, 100_000, PaymentMethod::Transfer, 'NGACO'))
        ->toThrow(DiscountNotApplicableException::class);

    expect($customer->payments()->count())->toBe(0);
});
