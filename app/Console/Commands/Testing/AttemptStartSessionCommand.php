<?php

namespace App\Console\Commands\Testing;

use App\Domain\Sessions\Actions\StartSessionAction;
use App\Domain\Sessions\SessionType;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

/**
 * Harness khusus test konkurensi (tests/Concurrency): dijalankan sebagai
 * proses OS terpisah supaya dua percobaan StartSessionAction pada unit
 * yang sama benar-benar berebut row lock database yang sesungguhnya,
 * bukan disimulasikan dalam satu proses PHP yang pada dasarnya single-thread.
 */
class AttemptStartSessionCommand extends Command
{
    protected $signature = 'testing:attempt-start-session {unit_id} {user_id}';

    protected $description = 'Test harness only: attempt to start an open-play session, for concurrency testing.';

    public function handle(StartSessionAction $action): int
    {
        if (app()->isProduction()) {
            $this->error('Command ini hanya untuk testing.');

            return self::FAILURE;
        }

        $unit = Unit::findOrFail((int) $this->argument('unit_id'));
        $user = User::findOrFail((int) $this->argument('user_id'));

        try {
            $session = $action->handle($unit, $user, SessionType::Open);
            $this->line("SUCCESS:{$session->id}");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line('FAILURE:'.$e::class);

            return self::FAILURE;
        }
    }
}
