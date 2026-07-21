<?php

namespace App\Domain\Wallet\Actions;

use App\Domain\Billing\MidtransGateway;
use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Discounts\DiscountEngine;
use App\Domain\Discounts\DiscountTarget;
use App\Models\Customer;
use App\Models\Payment;
use InvalidArgumentException;
use RuntimeException;

/**
 * Membuat tagihan isi saldo. Saldonya sendiri baru bertambah setelah
 * pembayarannya terbukti (lihat ApplySettledPaymentAction).
 *
 * Urutannya sama dengan pesanan kios, dan alasannya sama: menambah saldo lebih
 * dulu lalu berharap pembayarannya menyusul berarti memberikan uang yang belum
 * pernah diterima.
 */
class OpenTopUpAction
{
    /**
     * Batas bawah menutup pengisian receh yang biayanya lebih besar dari
     * nilainya; batas atas menutup salah ketik nol yang membuat pelanggan
     * dihadapkan tagihan jutaan.
     */
    public const MINIMUM = 10_000;

    public const MAXIMUM = 2_000_000;

    public function __construct(
        private readonly MidtransGateway $gateway,
        private readonly DiscountEngine $discounts,
    ) {}

    /**
     * @return array{payment: Payment, qr_url: ?string}
     */
    public function handle(Customer $customer, int $amount, PaymentMethod $method, ?string $voucherCode = null): array
    {
        if ($amount < self::MINIMUM || $amount > self::MAXIMUM) {
            throw new InvalidArgumentException('Nominal isi saldo di luar batas yang diizinkan.');
        }

        if (! $customer->is_active) {
            throw new InvalidArgumentException('Akun ini sedang tidak aktif.');
        }

        // Tunai tetap lewat kasir: tidak ada cara memastikan uang tunai
        // berpindah tanpa manusia yang menerimanya.
        if ($method === PaymentMethod::Cash) {
            throw new InvalidArgumentException('Isi saldo tunai dilayani kasir.');
        }

        // Voucher divalidasi SEKARANG (kode salah ditolak sebelum QR dibuat),
        // tapi bonusnya BARU dikreditkan saat pembayaran lunas (lihat
        // ApplySettledPaymentAction) — bonus tak pernah keluar tanpa uang masuk.
        if ($voucherCode) {
            $this->discounts->preview($voucherCode, DiscountTarget::TopUp, $amount, $customer);
        }

        // Tagihan isi saldo yang belum dibayar dibatalkan lebih dulu. Membiarkan
        // beberapa QR hidup sekaligus berarti pelanggan bisa membayar dua-duanya
        // dan mengira saldonya bertambah sekali.
        Payment::query()
            ->where('customer_id', $customer->id)
            ->whereNull('rental_session_id')
            ->where('status', PaymentStatus::Pending)
            ->update(['status' => PaymentStatus::Expired]);

        $payment = Payment::create([
            'customer_id' => $customer->id,
            'method' => $method,
            'status' => PaymentStatus::Pending,
            'amount' => $amount,
            'voucher_code' => $voucherCode,
        ]);

        if ($method !== PaymentMethod::Qris) {
            return ['payment' => $payment, 'qr_url' => null];
        }

        $created = $this->gateway->createQris($payment);

        if ($created === null) {
            $payment->update(['status' => PaymentStatus::Expired]);

            throw new RuntimeException('QRIS sedang tidak bisa dibuat. Coba lagi, atau isi saldo lewat kasir.');
        }

        $payment->update(['reference' => $created['reference']]);

        return ['payment' => $payment->fresh(), 'qr_url' => $created['qr_url']];
    }
}
