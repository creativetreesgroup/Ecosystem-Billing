<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Wallet\Wallet;
use App\Models\Payment;

/**
 * Satu pintu untuk "uangnya sudah masuk, lalu apa?".
 *
 * Sebuah pembayaran yang lunas berarti dua hal berbeda tergantung untuk apa ia
 * dibuat: tagihan sesi kios menjalankan sesinya, isi saldo menambah dompet
 * pelanggan. Keduanya datang dari sumber yang sama — QRIS yang dikonfirmasi
 * gateway, atau bukti transfer yang diterima kasir — jadi percabangannya
 * tinggal di SATU tempat. Menyebarnya ke tiap pemanggil berarti menambah cara
 * bayar baru nanti harus mengingat semua tempat yang perlu ikut diubah.
 *
 * Aman dipanggil berkali-kali: penjadwal menanyakan status tiap menit.
 */
class ApplySettledPaymentAction
{
    public function __construct(
        private readonly StartPaidKioskSessionAction $startSession,
        private readonly Wallet $wallet,
    ) {}

    public function handle(Payment $payment): void
    {
        if (! $payment->isSettled()) {
            return;
        }

        if ($payment->rental_session_id !== null) {
            $this->startSession->handle($payment);

            return;
        }

        if ($payment->customer === null) {
            return;
        }

        // Penjaga ganda-kredit: buku besar mencatat payment_id, jadi pembayaran
        // yang sudah pernah menambah saldo tidak pernah menambahkannya lagi.
        // Tanpa ini, penjadwal yang menanyakan status tiap menit akan
        // menggandakan saldo pelanggan setiap putaran.
        $sudahDikreditkan = $payment->customer
            ->walletTransactions()
            ->where('payment_id', $payment->id)
            ->exists();

        if ($sudahDikreditkan) {
            return;
        }

        $this->wallet->topUp($payment->customer, $payment->amount, $payment);
    }
}
