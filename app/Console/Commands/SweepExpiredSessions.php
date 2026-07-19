<?php

namespace App\Console\Commands;

use App\Domain\Sessions\Actions\CompleteSessionAction;
use App\Domain\Sessions\SessionStatus;
use App\Models\RentalSession;
use Illuminate\Console\Command;

/**
 * Jaring pengaman untuk expiry engine: menyelesaikan sesi paket yang
 * ends_at-nya sudah lewat > 30 detik tapi masih berstatus active — menutup
 * kasus queue worker restart atau delayed job yang hilang. Aman ditabrakkan
 * dengan ExpireRentalSession job karena CompleteSessionAction idempotent.
 */
class SweepExpiredSessions extends Command
{
    protected $signature = 'sessions:sweep-expired';

    protected $description = 'Selesaikan sesi paket yang sudah lewat waktu tapi belum diselesaikan.';

    public function handle(CompleteSessionAction $completeSession): int
    {
        $sessions = RentalSession::query()
            ->where('status', SessionStatus::Active)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now()->subSeconds(30))
            ->get();

        foreach ($sessions as $session) {
            $completeSession->handle($session);
        }

        $this->info("Sweep selesai: {$sessions->count()} sesi terlewat diselesaikan.");

        return self::SUCCESS;
    }
}
