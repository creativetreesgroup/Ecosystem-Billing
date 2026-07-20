<?php

namespace App\Console\Commands;

use App\Domain\Billing\Actions\SettleQrisPaymentAction;
use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Models\Payment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Menanyakan status QRIS yang masih menggantung ke Midtrans.
 *
 * Menggantikan webhook. Midtrans biasanya memanggil balik server merchant,
 * tapi mesin outlet tidak boleh menerima koneksi dari internet (§14) — dan
 * membuka port untuk itu berarti menaruh mesin yang memegang uang di jalur
 * yang bisa dijangkau siapa saja. Menanya keluar setiap menit jauh lebih murah
 * daripada risiko itu, dan pelanggan tetap melihat pembayarannya diakui dalam
 * hitungan detik.
 */
#[Signature('payments:poll-qris {--minutes=60 : Sejauh mana ke belakang pembayaran menggantung masih ditanyakan}')]
#[Description('Perbarui status pembayaran QRIS yang masih menunggu, langsung dari Midtrans')]
class PollQrisPayments extends Command
{
    public function handle(SettleQrisPaymentAction $settle): int
    {
        $menggantung = Payment::query()
            ->where('method', PaymentMethod::Qris)
            ->where('status', PaymentStatus::Pending)
            ->whereNotNull('reference')
            // Yang jauh lebih tua dari itu tidak akan pernah dibayar lagi;
            // menanyakannya terus hanya membebani gateway tiap menit selamanya.
            ->where('created_at', '>=', now()->subMinutes(max(1, (int) $this->option('minutes'))))
            ->get();

        if ($menggantung->isEmpty()) {
            return self::SUCCESS;
        }

        $berubah = $menggantung
            ->map(fn (Payment $payment): Payment => $settle->handle($payment))
            ->filter(fn (Payment $payment): bool => $payment->status !== PaymentStatus::Pending)
            ->count();

        $this->info("{$menggantung->count()} pembayaran diperiksa, {$berubah} berubah status.");

        return self::SUCCESS;
    }
}
