<?php

namespace App\Domain\Wallet\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Saldo pelanggan menembus nol karena kredit Open Play (bukan koreksi manual
 * owner — itu keputusan sadar owner sendiri). Ini utang yang timbul OTOMATIS,
 * dan owner perlu tahu untuk menagihnya.
 */
class CustomerWentIntoDebt
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly int $customerId) {}
}
