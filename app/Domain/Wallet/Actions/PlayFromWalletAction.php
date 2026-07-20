<?php

namespace App\Domain\Wallet\Actions;

use App\Domain\Devices\DeviceManager;
use App\Domain\Sessions\Events\SessionStarted;
use App\Domain\Sessions\Exceptions\UnitAlreadyActiveException;
use App\Domain\Sessions\Jobs\ExpireRentalSession;
use App\Domain\Sessions\Jobs\WarnSessionEnding;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Domain\Settings\SettingKey;
use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Wallet;
use App\Models\Customer;
use App\Models\Package;
use App\Models\RentalSession;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Pelanggan yang saldonya cukup langsung main — tanpa QR, tanpa kasir.
 *
 * Inilah yang membuat saldo bernilai bagi pelanggan: isi sekali, lalu tiap
 * kunjungan berikutnya cukup pindai dan pilih paket.
 *
 * Saldo dipotong DI DALAM transaksi yang sama dengan pembuatan sesinya. Kalau
 * dipisah, kegagalan di antaranya meninggalkan salah satu dari dua keadaan
 * yang sama buruknya: pelanggan bermain tanpa dipotong, atau saldonya terpotong
 * tanpa pernah bermain.
 */
class PlayFromWalletAction
{
    public function __construct(
        private readonly Wallet $wallet,
        private readonly DeviceManager $devices,
    ) {}

    public function handle(Customer $customer, Unit $unit, Package $package): RentalSession
    {
        if ($package->unit_type_id !== $unit->unit_type_id) {
            throw new InvalidArgumentException('Paket ini tidak berlaku untuk tipe unit tersebut.');
        }

        if (! $package->is_active || ! $unit->is_active || ! $customer->is_active) {
            throw new InvalidArgumentException('Unit, paket, atau akun sedang tidak tersedia.');
        }

        // Diperiksa lebih dulu supaya pelanggan mendapat pesan yang masuk akal.
        // Penjaga sesungguhnya tetap di Wallet, di dalam kunci baris.
        if (! $customer->canAfford($package->price)) {
            throw new InsufficientBalanceException('Saldo belum cukup untuk paket ini.');
        }

        $session = DB::transaction(function () use ($customer, $unit, $package): RentalSession {
            $lockedUnit = Unit::query()->whereKey($unit->id)->lockForUpdate()->firstOrFail();

            if ($lockedUnit->activeSession()->exists()) {
                throw new UnitAlreadyActiveException("Unit {$lockedUnit->code} sedang dipakai.");
            }

            $startedAt = now();

            $session = RentalSession::create([
                'unit_id' => $lockedUnit->id,
                'opened_by' => self::kioskOperator()->id,
                'customer_id' => $customer->id,
                'package_id' => $package->id,
                'customer_name' => $customer->name,
                'type' => SessionType::Package,
                'status' => SessionStatus::Active,
                'started_at' => $startedAt,
                'ends_at' => $startedAt->copy()->addMinutes($package->duration_minutes),
                'expiry_token' => (string) Str::uuid(),
                'base_amount' => $package->price,
                'extra_amount' => 0,
                'total_amount' => $package->price,
                'paid_at' => $startedAt,
            ]);

            $this->wallet->spend($customer, $package->price, $session);

            return $session;
        });

        // Di luar transaksi: perangkat & antrean tidak boleh menahan kunci
        // baris, dan kegagalannya tidak boleh membatalkan saldo yang sudah
        // terpotong untuk sesi yang sah (prinsip arsitektur #1).
        $this->devices->powerOn($session->unit);

        $warning = (int) Setting::get(SettingKey::WarningBeforeMinutes);
        ExpireRentalSession::dispatch($session->id, $session->expiry_token)->delay($session->ends_at);
        WarnSessionEnding::dispatch($session->id, $session->expiry_token)
            ->delay($session->ends_at->copy()->subMinutes($warning));

        SessionStarted::dispatch($session->id, $session->unit_id);

        return $session;
    }

    private static function kioskOperator(): User
    {
        return User::query()->where('role', UserRole::Owner)->orderBy('id')->firstOrFail();
    }
}
