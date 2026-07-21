<?php

namespace App\Listeners;

use App\Domain\Devices\Events\DeviceAlertRaised;
use App\Models\DeviceAlert;
use App\Notifications\BellNotifier;
use Filament\Notifications\Notification;

/**
 * Alert perangkat (TV tak menjawab, daya tak cocok) → notifikasi tersimpan di
 * lonceng panel untuk SEMUA staf aktif. Grid unit sudah berkedip realtime, tapi
 * itu hilang begitu halaman ditutup; lonceng menyimpannya sampai ditangani.
 */
class SendDeviceAlertNotification
{
    public function handle(DeviceAlertRaised $event): void
    {
        $alert = DeviceAlert::with('unit')->find($event->alertId);

        if ($alert === null) {
            return;
        }

        BellNotifier::send(
            Notification::make()
                ->title('Alert perangkat — '.($alert->unit?->code ?? 'unit'))
                ->body($alert->message)
                ->icon('heroicon-o-exclamation-triangle')
                ->danger()
        );
    }
}
