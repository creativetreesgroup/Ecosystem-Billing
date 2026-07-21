<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Discounts\DiscountEngine;
use App\Domain\Discounts\DiscountTarget;
use App\Domain\Discounts\Exceptions\DiscountNotApplicableException;
use App\Domain\Wallet\Events\WalletToppedUp;
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

        // Voucher isi saldo memberi BONUS saldo, dan HANYA di sini — saat uangnya
        // benar-benar masuk. Bonus + kredit dibungkus satu transaksi supaya tak
        // pernah ada bonus tanpa kredit (atau sebaliknya). Voucher yang jadi tak
        // berlaku sejak checkout (mis. kuota habis) → kredit polos + log, jangan
        // gagalkan penyelesaian: uangnya sudah masuk.
        $bonus = 0;
        $credited = false;

        DB::transaction(function () use ($payment, &$bonus, &$credited): void {
            // Kunci baris pelanggan, LALU cek "sudah dikreditkan" DI DALAM kunci.
            // Buku besar mencatat payment_id; dulu ceknya di luar transaksi, jadi
            // dua penyelesaian bersamaan untuk pembayaran yang sama sama-sama
            // membaca "belum" lalu mengkredit dua kali. Mengunci + mengecek dalam
            // satu transaksi membuatnya idempoten sungguhan — aman walau nanti
            // ditambah webhook atau tombol "cek ulang QRIS".
            $customer = $payment->customer->newQuery()
                ->whereKey($payment->customer_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($customer->walletTransactions()->where('payment_id', $payment->id)->exists()) {
                return;
            }

            // Voucher (disimpan saat checkout) atau promo otomatis bila tak ada.
            try {
                $bonus = $this->discounts->apply(
                    $payment->voucher_code,
                    DiscountTarget::TopUp,
                    $payment->amount,
                    $customer,
                    ['payment_id' => $payment->id],
                )?->amount ?? 0;
            } catch (DiscountNotApplicableException $e) {
                Log::warning('Voucher isi saldo gagal ditebus saat lunas; dikredit tanpa bonus.', [
                    'payment_id' => $payment->id,
                    'reason' => $e->getMessage(),
                ]);
            }

            $this->wallet->topUp($customer, $payment->amount + $bonus, $payment);
            $credited = true;
        });

        // Di luar transaksi: konfirmasi ke pelanggan (WhatsApp) bahwa saldonya
        // sudah bertambah. HANYA bila kredit ini yang baru terjadi — bukan
        // penyelesaian ulang yang no-op. Best-effort — kegagalannya tak boleh
        // membatalkan saldo yang sudah masuk.
        if ($credited) {
            WalletToppedUp::dispatch($payment->customer_id, $payment->amount, $bonus);
        }
    }
}
