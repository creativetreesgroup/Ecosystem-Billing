<?php

namespace App\Listeners;

use App\Domain\Billing\Rupiah;
use App\Domain\Wallet\Events\WalletToppedUp;
use App\Models\Customer;
use App\Notifications\CustomerNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Konfirmasi "isi saldo berhasil" ke WhatsApp — termasuk bonus voucher bila ada,
 * dan saldo terkini supaya pelanggan langsung yakin uangnya masuk.
 */
class SendTopUpConfirmationWhatsApp implements ShouldQueue
{
    public function __construct(private readonly CustomerNotifier $notifier) {}

    public function handle(WalletToppedUp $event): void
    {
        $customer = Customer::find($event->customerId);

        if (! $customer) {
            return;
        }

        $bonusLine = $event->bonus > 0
            ? ' (termasuk bonus '.Rupiah::format($event->bonus).')'
            : '';

        $this->notifier->notify(
            $customer,
            "Isi saldo {$this->rupiah($event->amount)} berhasil{$bonusLine}. "
            ."Saldo kamu sekarang {$this->rupiah($customer->balance)}.",
        );
    }

    private function rupiah(int $amount): string
    {
        return Rupiah::format($amount);
    }
}
