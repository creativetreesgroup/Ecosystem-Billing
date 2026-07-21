<?php

namespace App\Domain\Sessions\Actions;

use App\Domain\Devices\DeviceManager;
use App\Domain\Sessions\Events\SessionEnded;
use App\Domain\Sessions\Exceptions\IllegalSessionTransitionException;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Wallet\Wallet;
use App\Models\RentalSession;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class VoidSessionAction
{
    public function __construct(
        private readonly DeviceManager $devices,
        private readonly Wallet $wallet,
    ) {}

    public function handle(RentalSession $session, User $voidedBy, string $reason): RentalSession
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Alasan void wajib diisi.');
        }

        $voided = DB::transaction(function () use ($session, $voidedBy, $reason) {
            $locked = RentalSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === SessionStatus::Voided) {
                throw new IllegalSessionTransitionException('Sesi ini sudah di-void sebelumnya.');
            }

            $before = ['status' => $locked->status->value];
            $wasActive = $locked->status === SessionStatus::Active;

            // Kembalikan saldo sebesar yang BENAR-BENAR ditarik dari dompet untuk
            // sesi ini — dihitung dari buku besar, bukan baris Payment. Sumber ini
            // menutup kedua jalur sekaligus: PlayFromWallet memotong di awal tanpa
            // baris Payment, Open Play mencatat baris Saldo saat berhenti; keduanya
            // meninggalkan transaksi dompet ber-rental_session_id. Void
            // mengeluarkan sesi dari pendapatan, jadi tanpa refund pelanggan tetap
            // terpotong untuk sesi yang justru dibatalkan outlet. Pembayaran
            // non-dompet (QRIS/tunai/transfer) tidak punya transaksi dompet →
            // otomatis nol di sini; refund fisiknya urusan kasir.
            if ($locked->customer) {
                $charged = -(int) WalletTransaction::query()
                    ->where('rental_session_id', $locked->id)
                    ->sum('amount');

                if ($charged > 0) {
                    $this->wallet->refund($locked->customer, $charged, $locked, $voidedBy);
                }
            }

            $locked->update([
                'status' => SessionStatus::Voided,
                'voided_by' => $voidedBy->id,
                'void_reason' => $reason,
                'ended_at' => $locked->ended_at ?? now(),
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($voidedBy)
                ->withProperties(['before' => $before, 'after' => ['status' => $locked->status->value], 'reason' => $reason])
                ->event('voided')
                ->log('Sesi di-void');

            if ($wasActive) {
                $this->devices->powerOff($locked->unit);
            }

            return $locked->fresh();
        });

        SessionEnded::dispatch($voided->id, $voided->unit_id);

        return $voided;
    }
}
