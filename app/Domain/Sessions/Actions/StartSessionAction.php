<?php

namespace App\Domain\Sessions\Actions;

use App\Domain\Billing\PaymentMethod;
use App\Domain\Devices\DeviceManager;
use App\Domain\Sessions\Events\SessionStarted;
use App\Domain\Sessions\Exceptions\UnitAlreadyActiveException;
use App\Domain\Sessions\Jobs\ExpireRentalSession;
use App\Domain\Sessions\Jobs\WarnSessionEnding;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Models\Package;
use App\Models\RentalSession;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class StartSessionAction
{
    public function __construct(private readonly DeviceManager $devices) {}

    public function handle(
        Unit $unit,
        User $openedBy,
        SessionType $type,
        ?Package $package = null,
        ?string $customerName = null,
        ?PaymentMethod $paymentMethod = null,
    ): RentalSession {
        if ($type === SessionType::Package && ! $package) {
            throw new InvalidArgumentException('Paket wajib dipilih untuk sesi tipe paket.');
        }

        if ($type === SessionType::Package && ! $paymentMethod) {
            throw new InvalidArgumentException('Metode pembayaran wajib dipilih — paket dibayar di muka.');
        }

        // §8: jangan percaya state Livewire sebagai fakta — dropdown paket di
        // widget sudah difilter per unit_type, tapi itu cuma UI. Tanpa cek ini,
        // request yang di-tamper bisa memasang harga paket tipe unit lain
        // (mis. paket Non-VIP murah di unit Sultan) langsung ke base_amount.
        if ($package && $package->unit_type_id !== $unit->unit_type_id) {
            throw new InvalidArgumentException("Paket \"{$package->name}\" bukan untuk tipe unit {$unit->code}.");
        }

        $session = DB::transaction(function () use ($unit, $openedBy, $type, $package, $customerName, $paymentMethod) {
            $lockedUnit = Unit::query()->whereKey($unit->id)->lockForUpdate()->firstOrFail();

            $alreadyActive = RentalSession::query()
                ->where('unit_id', $lockedUnit->id)
                ->where('status', SessionStatus::Active)
                ->exists();

            if ($alreadyActive) {
                throw new UnitAlreadyActiveException("Unit {$lockedUnit->code} sudah memiliki sesi aktif.");
            }

            $startedAt = now();
            $endsAt = $type === SessionType::Package
                ? $startedAt->copy()->addMinutes($package->duration_minutes)
                : null;

            $session = RentalSession::create([
                'unit_id' => $lockedUnit->id,
                'opened_by' => $openedBy->id,
                'customer_name' => $customerName,
                'type' => $type,
                'package_id' => $package?->id,
                'started_at' => $startedAt,
                'ends_at' => $endsAt,
                'status' => SessionStatus::Active,
                'expiry_token' => (string) Str::uuid(),
                'base_amount' => $type === SessionType::Package ? $package->price : 0,
                'extra_amount' => 0,
                'payment_method' => $type === SessionType::Package ? $paymentMethod : null,
                'paid_at' => $type === SessionType::Package ? now() : null,
            ]);

            $this->devices->attempt($lockedUnit, fn ($driver) => $driver->powerOn($lockedUnit));

            if ($endsAt) {
                $warningMinutes = Setting::get('warning_before_minutes')['minutes'] ?? 5;

                ExpireRentalSession::dispatch($session->id, $session->expiry_token)->delay($endsAt);
                WarnSessionEnding::dispatch($session->id, $session->expiry_token)
                    ->delay($endsAt->copy()->subMinutes($warningMinutes));
            }

            return $session;
        });

        SessionStarted::dispatch($session->id, $session->unit_id);

        return $session;
    }
}
