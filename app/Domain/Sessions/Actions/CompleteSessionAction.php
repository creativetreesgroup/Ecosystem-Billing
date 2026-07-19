<?php

namespace App\Domain\Sessions\Actions;

use App\Domain\Billing\OpenPlayBillingCalculator;
use App\Domain\Billing\PaymentMethod;
use App\Domain\Devices\DeviceManager;
use App\Domain\Sessions\Events\SessionEnded;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Models\RentalSession;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CompleteSessionAction
{
    public function __construct(private readonly DeviceManager $devices) {}

    public function handle(RentalSession $session, ?PaymentMethod $paymentMethod = null): RentalSession
    {
        if ($session->type === SessionType::Open && ! $session->payment_method && ! $paymentMethod) {
            throw new InvalidArgumentException('Metode pembayaran wajib dipilih untuk sesi open play.');
        }

        $justCompleted = false;

        $completed = DB::transaction(function () use ($session, $paymentMethod, &$justCompleted) {
            $locked = RentalSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SessionStatus::Active) {
                // Idempotent: job expiry dan sweep bisa balapan menyelesaikan sesi
                // yang sama — panggilan kedua tidak boleh mengubah apa pun, termasuk
                // tidak mengirim ulang perintah TV off atau broadcast SessionEnded.
                return $locked;
            }

            $before = ['status' => $locked->status->value, 'total_amount' => $locked->total_amount];

            $endedAt = now();

            $totalAmount = $locked->type === SessionType::Open
                ? OpenPlayBillingCalculator::calculate(
                    elapsedSeconds: $locked->started_at->diffInSeconds($endedAt),
                    hourlyRateRupiah: $locked->unit->unitType->hourly_rate,
                    incrementMinutes: Setting::get('billing_increment_minutes')['minutes'] ?? 1,
                )
                : $locked->base_amount + $locked->extra_amount;

            $locked->update([
                'ended_at' => $endedAt,
                'status' => SessionStatus::Completed,
                'total_amount' => $totalAmount,
                'payment_method' => $locked->payment_method ?? $paymentMethod,
                'paid_at' => $locked->paid_at ?? $endedAt,
            ]);

            activity()
                ->performedOn($locked)
                ->withProperties(['before' => $before, 'after' => ['status' => $locked->status->value, 'total_amount' => $locked->total_amount]])
                ->event('completed')
                ->log('Sesi selesai');

            $this->devices->attempt($locked->unit, fn ($driver) => $driver->powerOff($locked->unit));

            $justCompleted = true;

            return $locked->fresh();
        });

        if ($justCompleted) {
            SessionEnded::dispatch($completed->id, $completed->unit_id);
        }

        return $completed;
    }
}
