<?php

use App\Domain\Billing\Actions\SettleQrisPaymentAction;
use App\Domain\Billing\MidtransGateway;
use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Billing\SalesSummary;
use App\Domain\Devices\IntegrationKey;
use App\Models\Integration;
use App\Models\Payment;
use App\Models\RentalSession;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Integration::query()->where('key', IntegrationKey::Midtrans)->delete();

    Integration::factory()->create([
        'key' => IntegrationKey::Midtrans,
        'base_url' => 'https://api.sandbox.midtrans.com',
        'token' => 'SB-Mid-server-uji',
        'is_active' => true,
    ]);

    $this->session = RentalSession::factory()->completedAwaitingPayment()->create(['total_amount' => 30_000]);
    $this->payment = $this->session->payments()->sole();
    $this->payment->update([
        'method' => PaymentMethod::Qris,
        'status' => PaymentStatus::Pending,
        'amount' => 30_000,
        'reference' => 'CTB-1-20260720',
    ]);
});

function midtransStatus(array $body): void
{
    Http::fake(['api.sandbox.midtrans.com/v2/*/status' => Http::response($body)]);
}

test('a settled transaction becomes revenue', function () {
    midtransStatus(['transaction_status' => 'settlement', 'gross_amount' => '30000.00']);

    $updated = app(SettleQrisPaymentAction::class)->handle($this->payment);

    expect($updated->status)->toBe(PaymentStatus::Paid)
        // verified_by sengaja KOSONG: tidak ada manusia yang memeriksanya,
        // gateway yang menyatakan. Perbedaan itu harus terbaca di laporan.
        ->and($updated->verified_by)->toBeNull()
        ->and($updated->verified_at)->not->toBeNull();

    $hari = now(SalesSummary::timezone())->toDateString();
    expect((new SalesSummary($hari, $hari))->totalRevenue())->toBe(30_000);
});

/**
 * Penjaga uang paling penting di berkas ini. Kalau gateway melaporkan nominal
 * yang berbeda dari yang kita tagih, transaksinya TIDAK boleh diakui lunas
 * berapa pun statusnya — menandainya lunas membuat laporan mencatat uang yang
 * tidak pernah ada.
 */
test('a settled transaction for the wrong amount is never accepted', function () {
    midtransStatus(['transaction_status' => 'settlement', 'gross_amount' => '15000.00']);

    $updated = app(SettleQrisPaymentAction::class)->handle($this->payment);

    expect($updated->status)->toBe(PaymentStatus::Pending);

    $hari = now(SalesSummary::timezone())->toDateString();
    expect((new SalesSummary($hari, $hari))->totalRevenue())->toBe(0);
});

/**
 * capture dengan fraud_status challenge berarti Midtrans SENDIRI belum yakin.
 * Menganggapnya lunas berarti mengakui uang yang masih bisa ditarik kembali.
 */
test('a capture the gateway itself is unsure about is not revenue', function () {
    midtransStatus(['transaction_status' => 'capture', 'fraud_status' => 'challenge', 'gross_amount' => '30000.00']);

    expect(app(SettleQrisPaymentAction::class)->handle($this->payment)->status)
        ->toBe(PaymentStatus::Pending);
});

test('it follows the gateway when a transaction expires or is denied', function (string $status, PaymentStatus $expected) {
    midtransStatus(['transaction_status' => $status, 'gross_amount' => '30000.00']);

    expect(app(SettleQrisPaymentAction::class)->handle($this->payment)->status)->toBe($expected);
})->with([
    'kedaluwarsa' => ['expire', PaymentStatus::Expired],
    'ditolak' => ['deny', PaymentStatus::Rejected],
    'dibatalkan' => ['cancel', PaymentStatus::Rejected],
]);

/**
 * Menutup transaksi yang sebenarnya berhasil jauh lebih merugikan daripada
 * memeriksanya sekali lagi semenit kemudian.
 */
test('an unknown status leaves the payment waiting rather than guessing', function () {
    midtransStatus(['transaction_status' => 'sesuatu_yang_baru', 'gross_amount' => '30000.00']);

    expect(app(SettleQrisPaymentAction::class)->handle($this->payment)->status)
        ->toBe(PaymentStatus::Pending);
});

test('the gateway being unreachable never changes anything', function () {
    Http::fake(['api.sandbox.midtrans.com/*' => Http::response('', 500)]);

    expect(app(SettleQrisPaymentAction::class)->handle($this->payment)->status)
        ->toBe(PaymentStatus::Pending);
});

/**
 * Penjadwal memanggil ini tiap menit. Pembayaran yang sudah selesai tidak boleh
 * berubah lagi hanya karena satu jawaban gateway yang aneh — pendapatan yang
 * sudah dicocokkan saat menutup kas tidak boleh bergeser setelahnya.
 */
test('a payment that is already settled is never touched again', function () {
    $this->payment->update(['status' => PaymentStatus::Paid, 'verified_at' => now()]);
    midtransStatus(['transaction_status' => 'expire', 'gross_amount' => '30000.00']);

    expect(app(SettleQrisPaymentAction::class)->handle($this->payment->fresh())->status)
        ->toBe(PaymentStatus::Paid);
});

test('nothing is attempted while Midtrans has no credentials', function () {
    Integration::query()->where('key', IntegrationKey::Midtrans)->update(['token' => null]);
    Http::fake();

    expect(app(MidtransGateway::class)->isConfigured())->toBeFalse()
        ->and(app(MidtransGateway::class)->createQris($this->payment))->toBeNull();

    Http::assertNothingSent();
});

test('sandbox is the default until production is switched on deliberately', function () {
    Integration::query()->where('key', IntegrationKey::Midtrans)
        ->update(['base_url' => null, 'options' => ['is_production' => false]]);

    expect(app(MidtransGateway::class)->baseUrl())->toBe('https://api.sandbox.midtrans.com');

    Integration::query()->where('key', IntegrationKey::Midtrans)
        ->update(['options' => ['is_production' => true]]);

    expect(app(MidtransGateway::class)->baseUrl())->toBe('https://api.midtrans.com');
});

test('creating a QRIS returns the reference and the QR image', function () {
    Http::fake(['api.sandbox.midtrans.com/v2/charge' => Http::response([
        'transaction_status' => 'pending',
        'actions' => [
            ['name' => 'generate-qr-code', 'url' => 'https://api.sandbox.midtrans.com/v2/qris/abc/qr-code'],
        ],
    ])]);

    $dibuat = app(MidtransGateway::class)->createQris($this->payment);

    expect($dibuat['qr_url'])->toBe('https://api.sandbox.midtrans.com/v2/qris/abc/qr-code')
        ->and($dibuat['reference'])->toStartWith('CTB-'.$this->payment->id.'-');
});

test('a payment with no QR image is not treated as created', function () {
    Http::fake(['api.sandbox.midtrans.com/v2/charge' => Http::response(['transaction_status' => 'pending', 'actions' => []])]);

    expect(app(MidtransGateway::class)->createQris($this->payment))->toBeNull();
});

test('Payment::factory qris helper stays consistent with the gateway', function () {
    $payment = Payment::factory()->qrisPending()->create();

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->method)->toBe(PaymentMethod::Qris);
});
