<?php

namespace App\Listeners;

use App\Domain\Billing\Rupiah;
use App\Domain\Wallet\Events\CustomerWentIntoDebt;
use App\Models\Customer;
use App\Notifications\CustomerNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * "Saldo kamu minus" ke WhatsApp pelanggan saat Open Play menembus saldo jadi
 * utang — menagih dengan halus sebelum kunjungan berikutnya.
 */
class SendDebtWhatsApp implements ShouldQueue
{
    public function __construct(private readonly CustomerNotifier $notifier) {}

    public function handle(CustomerWentIntoDebt $event): void
    {
        $customer = Customer::find($event->customerId);

        if (! $customer || $customer->balance >= 0) {
            return;
        }

        $this->notifier->notify(
            $customer,
            "Halo {$customer->name}, saldo kamu minus ".Rupiah::format(abs($customer->balance)).' dari Open Play. '
            .'Yuk lunasi dulu sebelum main lagi — cukup isi saldo di kios atau lewat kasir.',
        );
    }
}
