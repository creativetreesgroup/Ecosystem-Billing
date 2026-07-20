<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Exceptions\IllegalPaymentTransitionException;
use App\Domain\Billing\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Kasir menyatakan uangnya BENAR-BENAR sudah masuk ke rekening.
 *
 * Ini satu-satunya jalan sebuah transfer menjadi pendapatan. Sengaja tidak
 * pernah otomatis: bukti transfer bisa palsu, salah nominal, atau milik
 * transaksi lain, dan hanya manusia yang membuka mutasi rekening yang bisa
 * memastikannya. Namanya ikut tercatat — "sudah diverifikasi" harus punya
 * penanggung jawab, bukan sekadar status yang berubah sendiri.
 */
class VerifyPaymentAction
{
    public function handle(Payment $payment, User $verifiedBy): Payment
    {
        return DB::transaction(function () use ($payment, $verifiedBy): Payment {
            // Dikunci lebih dulu: dua kasir yang membuka daftar yang sama bisa
            // menekan Terima pada baris yang sama. Tanpa kunci, keduanya lolos
            // dan pemasukan tercatat ganda.
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentStatus::Paid) {
                throw new IllegalPaymentTransitionException('Pembayaran ini sudah diverifikasi sebelumnya.');
            }

            if ($locked->status !== PaymentStatus::AwaitingVerification) {
                throw new IllegalPaymentTransitionException('Hanya pembayaran yang menunggu verifikasi yang bisa diterima.');
            }

            $locked->update([
                'status' => PaymentStatus::Paid,
                'verified_by' => $verifiedBy->id,
                'verified_at' => now(),
                'rejection_reason' => null,
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($verifiedBy)
                ->withProperties(['amount' => $locked->amount, 'method' => $locked->method->value])
                ->event('payment_verified')
                ->log('Pembayaran diverifikasi');

            return $locked->fresh();
        });
    }
}
