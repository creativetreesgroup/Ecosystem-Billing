<?php

namespace App\Domain\Sessions\Actions;

use App\Domain\Sessions\Events\SessionExtended;
use App\Domain\Sessions\Exceptions\IllegalSessionTransitionException;
use App\Domain\Sessions\Jobs\ExpireRentalSession;
use App\Domain\Sessions\Jobs\WarnSessionEnding;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Models\RentalSession;
use App\Models\SessionExtension;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExtendSessionAction
{
    public function handle(RentalSession $session, int $addedMinutes, int $amount, User $user): RentalSession
    {
        $extended = DB::transaction(function () use ($session, $addedMinutes, $amount, $user) {
            $locked = RentalSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SessionStatus::Active || $locked->type !== SessionType::Package) {
                throw new IllegalSessionTransitionException('Hanya sesi paket yang sedang aktif yang bisa diperpanjang.');
            }

            $before = ['ends_at' => $locked->ends_at->toIso8601String(), 'extra_amount' => $locked->extra_amount];

            $newEndsAt = $locked->ends_at->copy()->addMinutes($addedMinutes);
            $newToken = (string) Str::uuid();

            $locked->update([
                'ends_at' => $newEndsAt,
                'extra_amount' => $locked->extra_amount + $amount,
                'expiry_token' => $newToken,
            ]);

            SessionExtension::create([
                'rental_session_id' => $locked->id,
                'added_minutes' => $addedMinutes,
                'amount' => $amount,
                'user_id' => $user->id,
            ]);

            activity()
                ->performedOn($locked)
                ->causedBy($user)
                ->withProperties(['before' => $before, 'after' => ['ends_at' => $newEndsAt->toIso8601String(), 'extra_amount' => $locked->extra_amount]])
                ->event('extended')
                ->log('Sesi diperpanjang');

            $warningMinutes = Setting::get('warning_before_minutes')['minutes'] ?? 5;

            ExpireRentalSession::dispatch($locked->id, $newToken)->delay($newEndsAt);
            WarnSessionEnding::dispatch($locked->id, $newToken)->delay($newEndsAt->copy()->subMinutes($warningMinutes));

            return $locked->fresh();
        });

        SessionExtended::dispatch($extended->id, $extended->unit_id, $extended->ends_at->toIso8601String());

        return $extended;
    }
}
