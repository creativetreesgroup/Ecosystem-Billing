<?php

namespace App\Console\Commands;

use App\Domain\Billing\Actions\ApplySettledPaymentAction;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Sessions\SessionStatus;
use App\Models\Payment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Jaring pengaman "uang masuk tapi efeknya tidak jalan".
 *
 * SettleQrisPaymentAction menandai pembayaran Lunas di dalam transaksi, lalu
 * MENJALANKAN akibatnya (kredit saldo / mulai sesi) DI LUAR transaksi itu. Kalau
 * langkah kedua gagal SETELAH commit pertama, pembayaran sudah Lunas tapi
 * efeknya tak pernah terjadi — dan poll berikutnya berhenti lebih awal (status
 * bukan Pending lagi), jadi tak pernah mencoba ulang. Akibatnya: pelanggan sudah
 * bayar, saldonya tak bertambah / sesinya macet Pending.
 *
 * Command ini menemukan pembayaran lunas yang efeknya belum jalan dan
 * menerapkannya ulang. ApplySettledPaymentAction idempoten, jadi aman: yang
 * sudah beres dilewati di bawah kunci.
 */
#[Signature('payments:reconcile-settled')]
#[Description('Terapkan ulang pembayaran lunas yang efeknya (saldo/sesi) belum jalan')]
class ReconcileSettledPayments extends Command
{
    public function handle(ApplySettledPaymentAction $applySettled): int
    {
        // Dibatasi ke pembayaran lunas yang belum lama supaya murah dijalankan
        // tiap beberapa menit. Yang lebih tua dan masih belum diterapkan sudah
        // pasti butuh campur tangan manusia, bukan retry otomatis.
        $since = now()->subDay();

        // 1) Isi saldo lunas TAPI saldo belum bertambah (tak ada baris buku besar
        //    ber-payment_id ini). Mencakup QRIS maupun transfer yang diverifikasi.
        $topUps = Payment::query()
            ->where('status', PaymentStatus::Paid)
            ->whereNull('rental_session_id')
            ->whereNotNull('customer_id')
            ->where('created_at', '>=', $since)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('wallet_transactions')
                    ->whereColumn('wallet_transactions.payment_id', 'payments.id');
            })
            ->get();

        // 2) Pembayaran sesi lunas TAPI sesinya masih Pending (belum dijalankan).
        $sessions = Payment::query()
            ->where('status', PaymentStatus::Paid)
            ->whereNotNull('rental_session_id')
            ->where('created_at', '>=', $since)
            ->whereHas('rentalSession', fn ($query) => $query->where('status', SessionStatus::Pending))
            ->get();

        $reapplied = 0;

        foreach ($topUps->concat($sessions) as $payment) {
            try {
                $applySettled->handle($payment);
                $reapplied++;
                $this->line("Reconcile pembayaran #{$payment->id}: efek diterapkan ulang.");
            } catch (\Throwable $e) {
                // Satu yang gagal tak boleh menghentikan sisanya — dilaporkan
                // (fail-loud) lalu lanjut. Putaran berikutnya mencoba lagi.
                report($e);
                $this->error("Gagal reconcile pembayaran #{$payment->id}: {$e->getMessage()}");
            }
        }

        $this->info("Selesai — {$reapplied} pembayaran diterapkan ulang.");

        return self::SUCCESS;
    }
}
