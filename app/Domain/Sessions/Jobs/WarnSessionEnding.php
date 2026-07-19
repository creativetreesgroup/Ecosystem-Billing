<?php

namespace App\Domain\Sessions\Jobs;

use App\Domain\Devices\Capability;
use App\Domain\Devices\DeviceManager;
use App\Domain\Sessions\Events\SessionEnding;
use App\Domain\Sessions\SessionStatus;
use App\Models\RentalSession;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class WarnSessionEnding implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $sessionId,
        public readonly string $expiryToken,
    ) {}

    public function handle(DeviceManager $devices): void
    {
        $session = RentalSession::find($this->sessionId);

        if (! $session || $session->status !== SessionStatus::Active || $session->expiry_token !== $this->expiryToken) {
            return;
        }

        SessionEnding::dispatch($session->id, $session->unit_id, $session->ends_at->toIso8601String());

        $unit = $session->unit;

        $devices->attempt($unit, function ($driver) use ($unit) {
            return $driver->supports($unit, Capability::Notify)
                ? $driver->notify($unit, 'Sesi akan berakhir sebentar lagi.')
                : null;
        });
    }
}
