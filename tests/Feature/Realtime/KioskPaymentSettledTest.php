<?php

use App\Domain\Billing\Events\KioskPaymentSettled;
use App\Domain\Billing\PaymentStatus;
use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;

/**
 * Pembayaran lunas didorong ke HP pelanggan lewat kanal PRIVAT miliknya sendiri,
 * dengan nama event yang didengarkan layar kios.
 */
test('the event broadcasts on the customer private channel', function () {
    $event = new KioskPaymentSettled(customerId: 42);

    expect($event->broadcastOn())->toEqual([new PrivateChannel('customer.42')])
        ->and($event->broadcastAs())->toBe('payment.settled');
});

/**
 * Peralihan status menjadi lunas mendorong pelanggannya — TEPAT sekali, di jalur
 * mana pun penyelesaiannya (QRIS gateway atau ACC kasir keduanya cuma mengubah
 * status).
 */
test('settling a customer payment dispatches the realtime push', function () {
    Event::fake([KioskPaymentSettled::class]);

    $customer = Customer::factory()->create();
    $payment = Payment::factory()->transferAwaitingVerification()->create(['customer_id' => $customer->id]);

    $payment->update(['status' => PaymentStatus::Paid]);

    Event::assertDispatched(KioskPaymentSettled::class, fn (KioskPaymentSettled $e) => $e->customerId === $customer->id);
});

/**
 * Tidak berisik: penyimpanan yang TIDAK mengubah status ke lunas tidak mendorong
 * apa pun (mis. polling penjadwal yang menyentuh baris tanpa mengubah statusnya).
 */
test('a save that does not settle the payment pushes nothing', function () {
    Event::fake([KioskPaymentSettled::class]);

    $customer = Customer::factory()->create();
    $payment = Payment::factory()->transferAwaitingVerification()->create(['customer_id' => $customer->id]);

    $payment->update(['proof_path' => 'payment-proofs/updated.jpg']); // status tak berubah

    Event::assertNotDispatched(KioskPaymentSettled::class);
});

/**
 * Pembayaran tanpa pelanggan (mis. sesi tamu prabayar) tidak punya kanal HP —
 * tak ada yang didorong.
 */
test('settling a payment without a customer pushes nothing', function () {
    Event::fake([KioskPaymentSettled::class]);

    $payment = Payment::factory()->qrisPending()->create(['customer_id' => null]);

    $payment->update(['status' => PaymentStatus::Paid]);

    Event::assertNotDispatched(KioskPaymentSettled::class);
});
