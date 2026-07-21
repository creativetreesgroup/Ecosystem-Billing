<?php

namespace App\Console\Commands;

use App\Domain\Billing\Actions\StopKioskOpenPlayAction;
use App\Domain\Billing\OpenPlay;
use App\Domain\Billing\SessionTotal;
use App\Domain\Sessions\Exceptions\SessionTooShortException;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Models\RentalSession;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Jaring pengaman plafon kredit Open Play.
 *
 * Saat HP pelanggan terbuka, layar kios menghentikan sendiri sesinya begitu
 * saldo efektif menyentuh −plafon. Tapi kalau HP-nya ditutup, tak ada yang
 * memicu itu — TV jalan terus dan tagihan yang melewati plafon jadi kerugian
 * outlet. Command ini dijalankan tiap menit: untuk tiap sesi Open Play kios yang
 * masih aktif, kalau tagihannya sudah mencapai titik saldo menembus plafon, ia
 * dihentikan (dan StopKioskOpenPlayAction menjepit hasilnya tepat di −plafon).
 */
#[Signature('openplay:enforce-ceiling')]
#[Description('Hentikan sesi Open Play kios yang mencapai plafon kredit −50k')]
class EnforceOpenPlayCeiling extends Command
{
    public function handle(StopKioskOpenPlayAction $stop): int
    {
        $now = now();

        // Hanya Open Play KIOS (punya customer_id → ditagih dari saldo). Open
        // Play yang dibuka kasir tidak punya saldo dan ditutup manual di meja.
        $sessions = RentalSession::query()
            ->where('status', SessionStatus::Active)
            ->where('type', SessionType::Open)
            ->whereNotNull('customer_id')
            ->with(['customer', 'unit.unitType'])
            ->get();

        $stopped = 0;

        foreach ($sessions as $session) {
            // Menit pertama tak bisa dihentikan (aturan StopKioskOpenPlayAction).
            // Sesi yang mencapai plafon pasti sudah jauh melewati ini, tapi
            // dijaga supaya command tak pernah melempar exception.
            if ((int) $session->started_at->diffInSeconds($now) < OpenPlay::MIN_SECONDS) {
                continue;
            }

            $bill = SessionTotal::for($session, $now);
            $ceilingBill = $session->customer->balance + OpenPlay::CREDIT_CEILING;

            if ($bill < $ceilingBill) {
                continue;
            }

            try {
                $stop->handle($session);
                $stopped++;
                $this->line("Auto-stop {$session->unit->code}: tagihan mencapai plafon kredit.");
            } catch (SessionTooShortException) {
                // Terlalu dini untuk dihentikan — biarkan, putaran berikutnya.
            }
        }

        $this->info("Selesai — {$stopped} sesi Open Play dihentikan di plafon.");

        return self::SUCCESS;
    }
}
