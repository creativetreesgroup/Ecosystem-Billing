<?php

namespace App\Console\Commands\Testing;

use App\Domain\Billing\Actions\StopKioskOpenPlayAction;
use App\Models\RentalSession;
use Illuminate\Console\Command;
use Throwable;

/**
 * Harness khusus test konkurensi (tests/Concurrency): dijalankan sebagai proses
 * OS terpisah supaya dua StopKioskOpenPlayAction pada dua sesi milik pelanggan
 * yang SAMA benar-benar berebut row lock baris pelanggan di database — bukan
 * disimulasikan dalam satu proses PHP.
 */
class AttemptStopOpenPlayCommand extends Command
{
    protected $signature = 'testing:attempt-stop-openplay {session_id}';

    protected $description = 'Test harness only: attempt to stop a kiosk Open Play session, for concurrency testing.';

    public function handle(StopKioskOpenPlayAction $action): int
    {
        if (app()->isProduction()) {
            $this->error('Command ini hanya untuk testing.');

            return self::FAILURE;
        }

        $session = RentalSession::findOrFail((int) $this->argument('session_id'));

        try {
            $action->handle($session);
            $this->line("SUCCESS:{$session->id}");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line('FAILURE:'.$e::class.':'.$e->getMessage());

            return self::FAILURE;
        }
    }
}
