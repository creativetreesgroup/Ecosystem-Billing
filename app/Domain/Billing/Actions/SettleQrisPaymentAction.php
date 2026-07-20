<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\MidtransGateway;
use App\Domain\Billing\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Menyesuaikan status pembayaran QRIS dengan apa yang dikatakan gateway.
 *
 * Sengaja BUKAN "tandai lunas": yang menentukan tetap Midtrans, kita hanya
 * mengikutinya. Dijalankan berulang oleh penjadwal, jadi harus aman dipanggil
 * berkali-kali untuk pembayaran yang sama.
 */
class SettleQrisPaymentAction
{
    public function __construct(
        private readonly MidtransGateway $gateway,
        private readonly ApplySettledPaymentAction $applySettled,
    ) {}

    public function handle(Payment $payment): Payment
    {
        // Yang sudah selesai tidak pernah disentuh lagi. Tanpa penjaga ini,
        // pembayaran yang sudah lunas bisa berubah status karena satu jawaban
        // gateway yang aneh — dan pendapatan yang sudah dicocokkan saat tutup
        // kas ikut berubah setelahnya.
        if ($payment->status !== PaymentStatus::Pending) {
            return $payment;
        }

        $status = $this->gateway->statusOf($payment);

        if ($status === null || $status === PaymentStatus::Pending) {
            return $payment;
        }

        $updated = DB::transaction(function () use ($payment, $status): Payment {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PaymentStatus::Pending) {
                return $locked;
            }

            $locked->update([
                'status' => $status,
                // verified_at, BUKAN verified_by: tidak ada manusia yang
                // memeriksanya — gateway yang menyatakan. Membiarkan
                // verified_by kosong membuat perbedaan itu terbaca di laporan.
                'verified_at' => $status === PaymentStatus::Paid ? now() : null,
            ]);

            activity()
                ->performedOn($locked)
                ->withProperties(['status' => $status->value, 'amount' => $locked->amount, 'reference' => $locked->reference])
                ->event('qris_'.$status->value)
                ->log('Status QRIS diperbarui dari gateway');

            return $locked->fresh();
        });

        // Uang lunas = akibatnya dijalankan (sesi kios berjalan, atau saldo
        // bertambah). Di luar transaksi supaya perintah TV dan penjadwalan
        // tidak menahan kunci baris pembayaran.
        $this->applySettled->handle($updated);

        return $updated;
    }
}
