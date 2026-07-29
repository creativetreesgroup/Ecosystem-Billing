<?php

use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Devices\IntegrationKey;
use App\Models\Integration;
use App\Models\Payment;
use App\Models\RentalSession;
use Illuminate\Support\Facades\Http;

/**
 * Command inilah pengganti webhook Midtrans — SATU-SATUNYA yang membuat QRIS
 * yang sudah dibayar berubah jadi lunas. MidtransGateway sendiri sudah diuji
 * terpisah, tapi gateway yang benar sekalipun tidak menolong bila yang memilih
 * pembayaran mana yang ditanyakan salah: pelanggan berdiri di depan QR yang
 * sudah dibayar dan tidak terjadi apa-apa, sementara seluruh suite tetap hijau.
 */
beforeEach(function () {
    Integration::query()->where('key', IntegrationKey::Midtrans)->delete();

    Integration::factory()->create([
        'key' => IntegrationKey::Midtrans,
        'base_url' => 'https://api.sandbox.midtrans.com',
        'token' => 'SB-Mid-server-uji',
        'is_active' => true,
    ]);
});

function pendingQris(array $overrides = []): Payment
{
    $payment = RentalSession::factory()->completedAwaitingPayment()->create(['total_amount' => 30_000])
        ->payments()->sole();

    $payment->update([
        'method' => PaymentMethod::Qris,
        'status' => PaymentStatus::Pending,
        'amount' => 30_000,
        'reference' => 'CTB-'.$payment->id.'-20260728',
        ...$overrides,
    ]);

    return $payment->fresh();
}

function midtransSays(string $status, string $amount = '30000.00'): void
{
    Http::fake([
        'api.sandbox.midtrans.com/v2/*/status' => Http::response([
            'transaction_status' => $status,
            'gross_amount' => $amount,
        ]),
    ]);
}

test('a QRIS paid at the gateway becomes paid here', function () {
    $payment = pendingQris();
    midtransSays('settlement');

    $this->artisan('payments:poll-qris')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

test('a QRIS still unpaid stays pending', function () {
    $payment = pendingQris();
    midtransSays('pending');

    $this->artisan('payments:poll-qris')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

test('an expired QRIS is closed so the unit is not held forever', function () {
    $payment = pendingQris();
    midtransSays('expire');

    $this->artisan('payments:poll-qris')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Expired);
});

/**
 * Penjaga uang: nominal yang berbeda tidak boleh diakui lunas, bahkan lewat
 * jalur otomatis yang tak pernah dilihat manusia.
 */
test('a QRIS settled for the wrong amount is never accepted', function () {
    $payment = pendingQris();
    midtransSays('settlement', '15000.00');

    $this->artisan('payments:poll-qris')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

test('a payment without a gateway reference is never asked about', function () {
    pendingQris(['reference' => null]);
    midtransSays('settlement');

    $this->artisan('payments:poll-qris')->assertSuccessful();

    Http::assertNothingSent();
});

test('cash and transfer are left alone; only QRIS is polled', function () {
    $tunai = pendingQris(['method' => PaymentMethod::Cash]);
    midtransSays('settlement');

    $this->artisan('payments:poll-qris')->assertSuccessful();

    expect($tunai->fresh()->status)->toBe(PaymentStatus::Pending);
    Http::assertNothingSent();
});

/**
 * Batas --minutes ada supaya QRIS lama tidak ditanyakan selamanya tiap 10
 * detik. Kalau batasnya bocor, gateway dibebani permintaan sia-sia yang
 * tumbuh tanpa henti seiring umur outlet.
 */
test('a stale QRIS beyond the window is no longer asked about', function () {
    $payment = pendingQris();
    $payment->forceFill(['created_at' => now()->subHours(3)])->save();
    midtransSays('settlement');

    $this->artisan('payments:poll-qris --minutes=60')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
    Http::assertNothingSent();
});

test('a payment inside the window is still asked about', function () {
    $payment = pendingQris();
    $payment->forceFill(['created_at' => now()->subMinutes(30)])->save();
    midtransSays('settlement');

    $this->artisan('payments:poll-qris --minutes=60')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});
