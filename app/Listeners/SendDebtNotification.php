<?php

namespace App\Listeners;

use App\Domain\Billing\Rupiah;
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
            // Siapa yang perlu tahu pelanggan berutang: yang mengurus uang.
            // Sebelumnya dipaku ke kolom role, sehingga peran Keuangan yang
            // dibuat lewat panel tidak pernah menerima pemberitahuan ini.
            scope: fn (Builder $query): Builder => $query->permission('ViewAny:Payment'),
        );
    }
}
