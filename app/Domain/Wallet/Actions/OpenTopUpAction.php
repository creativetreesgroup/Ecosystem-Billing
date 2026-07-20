<?php

namespace App\Domain\Wallet\Actions;

use App\Domain\Billing\MidtransGateway;
use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
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

    public function __construct(private readonly MidtransGateway $gateway) {}

    /**
     * @return array{payment: Payment, qr_url: ?string}
     */
    public function handle(Customer $customer, int $amount, PaymentMethod $method): array
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
