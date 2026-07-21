<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Discounts\DiscountEngine;
use App\Domain\Discounts\DiscountTarget;
use App\Domain\Discounts\Exceptions\DiscountNotApplicableException;
use App\Domain\Wallet\Wallet;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        private readonly DiscountEngine $discounts,
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

        // Voucher isi saldo memberi BONUS saldo, dan HANYA di sini — saat uangnya
        // benar-benar masuk. Bonus + kredit dibungkus satu transaksi supaya tak
        // pernah ada bonus tanpa kredit (atau sebaliknya). Voucher yang jadi tak
        // berlaku sejak checkout (mis. kuota habis) → kredit polos + log, jangan
        // gagalkan penyelesaian: uangnya sudah masuk.
        $bonus = 0;

        DB::transaction(function () use ($payment, &$bonus): void {
            if ($payment->voucher_code) {
                try {
                    $bonus = $this->discounts->redeem(
                        $payment->voucher_code,
                        DiscountTarget::TopUp,
                        $payment->amount,
                        $payment->customer,
                        ['payment_id' => $payment->id],
                    )->amount;
                } catch (DiscountNotApplicableException $e) {
                    Log::warning('Voucher isi saldo gagal ditebus saat lunas; dikredit tanpa bonus.', [
                        'payment_id' => $payment->id,
                        'reason' => $e->getMessage(),
                    ]);
                }
            }

            $this->wallet->topUp($payment->customer, $payment->amount + $bonus, $payment);
        });
    }
}
