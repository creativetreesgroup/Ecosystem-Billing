<?php

namespace App\Listeners;

use App\Domain\Billing\Rupiah;
use App\Domain\Sessions\Events\SessionEnded;
use App\Domain\Sessions\SessionStatus;
use App\Models\RentalSession;
use App\Notifications\CustomerNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Struk digital ringkas ke WhatsApp saat sesi selesai. Hanya untuk sesi milik
 * PELANGGAN yang benar-benar SELESAI (bukan di-void — SessionEnded juga menyala
 * saat void, dan "main selesai, total Rp X" pada sesi yang dibatalkan itu salah).
 */
class SendSessionSummaryWhatsApp implements ShouldQueue
{
    public function __construct(private readonly CustomerNotifier $notifier) {}

    public function handle(SessionEnded $event): void
    {
        $session = RentalSession::query()->with(['unit', 'customer'])->find($event->sessionId);

        if (! $session?->customer || $session->status !== SessionStatus::Completed) {
            return;
        }

        $this->notifier->notify(
            $session->customer,
            "Main di {$session->unit->code} selesai. Total {$this->rupiah($session->total_amount)}, "
            ."saldo kamu {$this->rupiah($session->customer->balance)}. Terima kasih sudah main di Creative Trees!",
        );
    }

    private function rupiah(?int $amount): string
    {
        return Rupiah::format((int) $amount);
    }
}
