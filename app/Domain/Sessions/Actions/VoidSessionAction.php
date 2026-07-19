<?php

namespace App\Domain\Sessions\Actions;

use App\Domain\Devices\DeviceManager;
use App\Domain\Sessions\Events\SessionEnded;
use App\Domain\Sessions\Exceptions\IllegalSessionTransitionException;
use App\Domain\Sessions\SessionStatus;
use App\Models\RentalSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class VoidSessionAction
{
    public function __construct(private readonly DeviceManager $devices) {}

    public function handle(RentalSession $session, User $voidedBy, string $reason): RentalSession
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Alasan void wajib diisi.');
        }

        $voided = DB::transaction(function () use ($session, $voidedBy, $reason) {
            $locked = RentalSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === SessionStatus::Voided) {
                throw new IllegalSessionTransitionException('Sesi ini sudah di-void sebelumnya.');
            }

            $before = ['status' => $locked->status->value];
            $wasActive = $locked->status === SessionStatus::Active;

            $locked->update([
                'status' => SessionStatus::Voided,
                'voided_by' => $voidedBy->id,
                'void_reason' => $reason,
                'ended_at' => $locked->ended_at ?? now(),
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($voidedBy)
                ->withProperties(['before' => $before, 'after' => ['status' => $locked->status->value], 'reason' => $reason])
                ->event('voided')
                ->log('Sesi di-void');

            if ($wasActive) {
                $this->devices->attempt($locked->unit, fn ($driver) => $driver->powerOff($locked->unit));
            }

            return $locked->fresh();
        });

        SessionEnded::dispatch($voided->id, $voided->unit_id);

        return $voided;
    }
}
