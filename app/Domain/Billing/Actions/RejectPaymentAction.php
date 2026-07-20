<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Exceptions\IllegalPaymentTransitionException;
use App\Domain\Billing\PaymentStatus;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Kasir menolak bukti yang diberikan pelanggan.
 *
 * Alasannya WAJIB dan disimpan: pelanggan berhak tahu kenapa buktinya ditolak,
 * dan saat ada sengketa, "ditolak" tanpa keterangan tidak bisa dipertanggung-
 * jawabkan oleh siapa pun. Sesinya sendiri tidak diubah — pelanggan masih
 * berutang, dan penagihannya diselesaikan dengan cara lain (mis. tunai).
 */
class RejectPaymentAction
{
    public function handle(Payment $payment, User $rejectedBy, string $reason): Payment
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Alasan penolakan wajib diisi.');
        }

        return DB::transaction(function () use ($payment, $rejectedBy, $reason): Payment {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentStatus::Paid) {
                throw new IllegalPaymentTransitionException('Pembayaran ini sudah terlanjur diverifikasi — batalkan lewat void sesi, bukan lewat penolakan bukti.');
            }

            if ($locked->status !== PaymentStatus::AwaitingVerification) {
                throw new IllegalPaymentTransitionException('Hanya pembayaran yang menunggu verifikasi yang bisa ditolak.');
            }

            $locked->update([
                'status' => PaymentStatus::Rejected,
                'verified_by' => $rejectedBy->id,
                'verified_at' => now(),
                'rejection_reason' => $reason,
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($rejectedBy)
                ->withProperties(['amount' => $locked->amount, 'reason' => $reason])
                ->event('payment_rejected')
                ->log('Bukti pembayaran ditolak');

            return $locked->fresh();
        });
    }
}
