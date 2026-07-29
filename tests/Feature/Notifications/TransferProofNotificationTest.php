<?php

use App\Domain\Billing\Events\TransferProofSubmitted;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/**
 * Bukti transfer masuk → notifikasi lonceng untuk tiap staf aktif, memuat nama
 * pelanggan & nominal. Kasir tidak menatap panel; tanpa dorongan ini bukti bisa
 * menganggur dan pelanggan menunggu sia-sia di depan TV.
 */
test('submitting a transfer proof notifies every active staff member', function () {
    $active = User::factory()->create(['is_active' => true]);
    $inactive = User::factory()->create(['is_active' => false]);

    $customer = Customer::factory()->create(['name' => 'Budi']);
    $payment = Payment::factory()->transferAwaitingVerification()->create([
        'customer_id' => $customer->id,
        'amount' => 75_000,
    ]);

    TransferProofSubmitted::dispatch($payment->id);

    expect($active->fresh()->notifications()->count())->toBe(1)
        ->and($inactive->fresh()->notifications()->count())->toBe(0);

    $data = $active->fresh()->notifications()->first()->data;
    expect($data['title'] ?? '')->toContain('verifikasi')
        ->and($data['body'] ?? '')->toContain('Budi');
});

test('the transfer-proof notification listener is registered exactly once', function () {
    expect(Event::getListeners(TransferProofSubmitted::class))->toHaveCount(1);
});
