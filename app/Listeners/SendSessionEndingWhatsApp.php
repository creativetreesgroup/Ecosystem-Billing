<?php

namespace App\Listeners;

use App\Domain\Sessions\Events\SessionEnding;
use App\Models\RentalSession;
use App\Notifications\CustomerNotifier;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * "Sesimu mau habis" ke WhatsApp pelanggan. Queued: kirim WA adalah panggilan
 * HTTP dan tidak boleh menahan alur apa pun.
 */
class SendSessionEndingWhatsApp implements ShouldQueue
{
    public function __construct(private readonly CustomerNotifier $notifier) {}

    public function handle(SessionEnding $event): void
    {
        $session = RentalSession::query()->with(['unit', 'customer'])->find($event->sessionId);

        if (! $session?->customer) {
            return;
        }

        $secondsLeft = max(0, Carbon::parse($event->endsAt)->getTimestamp() - now()->getTimestamp());
        $minutesLeft = (int) ceil($secondsLeft / 60);
        $sisa = $minutesLeft > 0 ? "dalam {$minutesLeft} menit" : 'sebentar lagi';

        $this->notifier->notify(
            $session->customer,
            "Halo {$session->customer->name}, sesimu di {$session->unit->code} akan habis {$sisa}. "
            .'Mau lanjut? Perpanjang lewat kasir atau isi saldo di kios.',
        );
    }
}
