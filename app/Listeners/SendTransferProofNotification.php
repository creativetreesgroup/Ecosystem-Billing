<?php

namespace App\Listeners;

use App\Domain\Billing\Events\TransferProofSubmitted;
use App\Domain\Billing\Rupiah;
use App\Models\Payment;
use App\Notifications\BellNotifier;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Bukti transfer masuk → notifikasi lonceng untuk staf aktif, dengan tombol
 * langsung ke daftar Pembayaran supaya kasir bisa memverifikasi tanpa mencari.
 */
class SendTransferProofNotification
{
    public function handle(TransferProofSubmitted $event): void
    {
        $payment = Payment::with('customer')->find($event->paymentId);

        if ($payment === null) {
            return;
        }

        $who = $payment->customer?->name ?? 'Pelanggan';

        BellNotifier::send(
            Notification::make()
                ->title('Bukti transfer menunggu verifikasi')
                ->body($who.' — '.Rupiah::format($payment->amount))
                ->icon('heroicon-o-banknotes')
                ->warning()
                ->actions([
                    Action::make('verify')
                        ->label('Buka pembayaran')
                        ->url(route('filament.admin.resources.payments.index'))
                        ->markAsRead(),
                ])
        );
    }
}
