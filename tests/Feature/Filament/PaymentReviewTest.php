<?php

use App\Domain\Billing\PaymentStatus;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * Bukti transfer bisa datang dari ISI SALDO (rental_session_id null), bukan hanya
 * sesi. Membuka "Periksa bukti" untuk pembayaran isi saldo dulu meledak karena
 * mengakses ->rentalSession->unit yang null. Kini judul & konteksnya jatuh ke
 * nama pelanggan + "Isi saldo".
 */
test('reviewing a kiosk top-up transfer proof does not crash on the missing session', function () {
    $kasir = User::factory()->create();
    $customer = Customer::factory()->create(['name' => 'Budi']);

    $payment = Payment::factory()->transferAwaitingVerification()->create([
        'customer_id' => $customer->id,
        'rental_session_id' => null,
        'amount' => 40_000,
    ]);

    // Modal ter-mount tanpa meledak. Dulu modalHeading mengakses
    // ->rentalSession->unit pada pembayaran isi saldo (rentalSession null) →
    // "read property on null" saat kasir membuka modalnya.
    $action = TestAction::make('review')->table($payment);

    Livewire::actingAs($kasir)->test(ListPayments::class)
        ->mountAction($action)
        ->assertActionMounted($action)
        ->assertHasNoActionErrors();
});

/**
 * Menerima bukti isi saldo tetap menambah saldo pelanggan (lewat
 * ApplySettledPaymentAction) — jalur uangnya utuh, bukan cuma tak-crash.
 */
test('accepting a top-up transfer proof credits the customer wallet', function () {
    $kasir = User::factory()->create();
    $customer = Customer::factory()->create();

    $payment = Payment::factory()->transferAwaitingVerification()->create([
        'customer_id' => $customer->id,
        'rental_session_id' => null,
        'amount' => 40_000,
    ]);

    Livewire::actingAs($kasir)->test(ListPayments::class)
        ->callAction(TestAction::make('review')->table($payment))
        ->assertHasNoActionErrors();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($customer->fresh()->balance)->toBe(40_000);
});
