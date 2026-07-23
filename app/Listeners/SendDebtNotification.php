<?php

namespace App\Listeners;

use App\Domain\Billing\Rupiah;
use App\Domain\Users\UserRole;
use App\Domain\Wallet\Events\CustomerWentIntoDebt;
use App\Models\Customer;
use App\Notifications\BellNotifier;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;

/**
 * Utang Open Play → notifikasi lonceng khusus OWNER (bukan kasir): menagih
 * saldo minus adalah keputusan pemilik, dan angka merah di daftar member mudah
 * terlewat kalau tidak didorong.
 */
class SendDebtNotification
{
    public function handle(CustomerWentIntoDebt $event): void
    {
        $customer = Customer::find($event->customerId);

        if ($customer === null) {
            return;
        }

        BellNotifier::send(
            Notification::make()
                ->title('Saldo pelanggan minus')
                ->body($customer->name.' — '.Rupiah::format($customer->balance))
                ->icon('heroicon-o-exclamation-circle')
                ->danger(),
            scope: fn (Builder $query): Builder => $query->where('role', UserRole::Owner),
        );
    }
}
