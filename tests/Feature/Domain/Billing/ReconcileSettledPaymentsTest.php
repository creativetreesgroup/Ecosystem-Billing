<?php

use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Wallet\Wallet;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\WalletTransaction;

/**
 * Jaring pengaman untuk "uang masuk tapi efeknya tak jalan": sebuah pembayaran
 * bisa berstatus Lunas sementara kredit saldo / mulai sesinya gagal setelah
 * commit. payments:reconcile-settled menemukan & menerapkannya ulang, idempoten.
 */
test('reconcile re-credits a settled top-up whose wallet credit never ran', function () {
    $customer = Customer::factory()->withBalance(0)->create();

    // Pembayaran lunas (mis. QRIS dikonfirmasi gateway) TAPI applySettled tak
    // pernah menyelesaikannya → tak ada baris buku besar, saldo masih 0.
    $payment = Payment::create([
        'customer_id' => $customer->id,
        'method' => PaymentMethod::Qris,
        'status' => PaymentStatus::Paid,
        'amount' => 50_000,
        'verified_at' => now(),
    ]);

    expect($customer->fresh()->balance)->toBe(0)
        ->and(WalletTransaction::where('payment_id', $payment->id)->exists())->toBeFalse();

    $this->artisan('payments:reconcile-settled')->assertSuccessful();

    expect($customer->fresh()->balance)->toBe(50_000)
        ->and(WalletTransaction::where('payment_id', $payment->id)->count())->toBe(1);
});

test('reconcile is idempotent — never credits the same settled top-up twice', function () {
    $customer = Customer::factory()->withBalance(0)->create();

    $payment = Payment::create([
        'customer_id' => $customer->id,
        'method' => PaymentMethod::Qris,
        'status' => PaymentStatus::Paid,
        'amount' => 50_000,
        'verified_at' => now(),
    ]);

    $this->artisan('payments:reconcile-settled')->assertSuccessful();
    $this->artisan('payments:reconcile-settled')->assertSuccessful();

    expect($customer->fresh()->balance)->toBe(50_000)
        ->and(WalletTransaction::where('payment_id', $payment->id)->count())->toBe(1);
});

test('reconcile leaves an already-credited top-up untouched', function () {
    $customer = Customer::factory()->withBalance(0)->create();

    // Sudah dikreditkan normal (ada baris buku besar ber-payment_id ini).
    $payment = Payment::create([
        'customer_id' => $customer->id,
        'method' => PaymentMethod::Qris,
        'status' => PaymentStatus::Paid,
        'amount' => 30_000,
        'verified_at' => now(),
    ]);
    app(Wallet::class)->topUp($customer, 30_000, $payment);

    expect($customer->fresh()->balance)->toBe(30_000);

    $this->artisan('payments:reconcile-settled')->assertSuccessful();

    expect($customer->fresh()->balance)->toBe(30_000)
        ->and(WalletTransaction::where('payment_id', $payment->id)->count())->toBe(1);
});
