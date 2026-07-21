<?php

namespace App\Domain\Billing\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pembayaran seorang pelanggan LUNAS (QRIS dikonfirmasi gateway, atau kasir
 * meng-ACC transfer/tunai) — didorong ke HP-nya lewat kanal privat per pelanggan
 * supaya layar kios maju SEKETIKA, bukan menunggu polling.
 *
 * AfterCommit: hanya disiarkan bila transaksi penyelesaiannya benar-benar
 * commit — tidak pernah mengumumkan pembayaran yang ternyata di-rollback.
 */
class KioskPaymentSettled implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly int $customerId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('customer.'.$this->customerId)];
    }

    public function broadcastAs(): string
    {
        return 'payment.settled';
    }
}
