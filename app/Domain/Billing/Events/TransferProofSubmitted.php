<?php

namespace App\Domain\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pelanggan mengunggah bukti transfer di kios — pembayarannya kini menunggu
 * kasir memverifikasi. Kasir tidak berdiri menatap panel; tanpa dorongan, bukti
 * bisa menganggur dan pelanggan menunggu sia-sia di depan TV.
 */
class TransferProofSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly int $paymentId) {}
}
