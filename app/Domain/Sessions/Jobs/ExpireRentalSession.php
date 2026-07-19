<?php

namespace App\Domain\Sessions\Jobs;

use App\Domain\Sessions\Actions\CompleteSessionAction;
use App\Domain\Sessions\SessionStatus;
use App\Models\RentalSession;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dijadwalkan dengan delay() sampai ends_at saat sesi paket dibuat/diperpanjang.
 * Fencing via expiry_token: kalau sesi sudah diperpanjang atau diselesaikan
 * manual sebelum job ini jalan, token sudah berubah/status sudah bukan active,
 * dan job ini no-op — cara termurah "membatalkan" delayed job yang sudah
 * di-dispatch tanpa perlu ID job untuk di-cancel (lihat DECISIONS.md).
 */
class ExpireRentalSession implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $sessionId,
        public readonly string $expiryToken,
    ) {}

    public function handle(CompleteSessionAction $completeSession): void
    {
        $session = RentalSession::find($this->sessionId);

        if (! $session || $session->status !== SessionStatus::Active || $session->expiry_token !== $this->expiryToken) {
            return;
        }

        $completeSession->handle($session);
    }
}
