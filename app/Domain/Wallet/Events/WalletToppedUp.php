<?php

namespace App\Domain\Wallet\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Saldo pelanggan bertambah lewat isi saldo yang LUNAS (QRIS/transfer yang
 * terbukti, atau tunai yang diterima kasir) — beda dari refund/koreksi. Dipakai
 * untuk mengonfirmasi ke pelanggan lewat WhatsApp.
 */
class WalletToppedUp
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $customerId,
        public readonly int $amount,
        public readonly int $bonus = 0,
    ) {}
}
