<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\OpenPlay;
use App\Domain\Billing\PaymentMethod;
use App\Domain\Devices\DeviceManager;
use App\Domain\Discounts\DiscountEngine;
use App\Domain\Discounts\DiscountTarget;
use App\Domain\Sessions\Events\SessionStarted;
use App\Domain\Sessions\Exceptions\UnitAlreadyActiveException;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Domain\Wallet\Exceptions\CreditNotAllowedException;
use App\Models\Customer;
use App\Models\RentalSession;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Memulai sesi Open Play dari kios: main bebas, ditagih per menit saat berhenti.
 *
 * Tidak ada pemotongan saldo di sini — Open Play mengalir, tagihannya dihitung
 * saat berhenti (StopKioskOpenPlayAction). Yang dijaga di awal cuma satu:
 * boleh atau tidaknya akun ini berutang.
 */
class StartKioskOpenPlayAction
{
    public function __construct(
        private readonly DeviceManager $devices,
        private readonly DiscountEngine $discounts,
    ) {}

    public function handle(Customer $customer, Unit $unit, ?string $voucherCode = null): RentalSession
    {
        // Kredit hanya untuk akun yang pernah isi saldo. Akun bersaldo nol yang
        // belum pernah top-up akan berutang dari detik pertama — dan itu persis
        // celah akun-buang yang harus ditutup.
        if ($customer->balance <= 0 && ! $customer->isCreditEligible()) {
            throw new CreditNotAllowedException('Isi saldo dulu sebelum Open Play.');
        }

        // Sudah di/di bawah lantai plafon = pelanggan berutang penuh. Membiarkan
        // main lagi hanya menambah tagihan yang tidak bisa ditarik (headroom 0),
        // jadi wajib lunasi dulu.
        if ($customer->balance <= -OpenPlay::CREDIT_CEILING) {
            throw new CreditNotAllowedException('Lunasi utang dulu sebelum main lagi.');
        }

        // Voucher Open Play divalidasi SEKARANG (tanpa nominal — potongannya baru
        // dihitung dari tagihan saat berhenti) supaya kode salah ditolak sebelum
        // pelanggan main, bukan setelahnya. Kodenya disimpan; penebusannya di
        // StopKioskOpenPlayAction. min_amount 0 dianjurkan untuk voucher Open Play
        // karena tagihannya belum ada di sini — preview memakai base 0.
        if ($voucherCode) {
            $this->discounts->preview($voucherCode, DiscountTarget::OpenPlay, 0, $customer);
        }

        $session = DB::transaction(function () use ($customer, $unit, $voucherCode): RentalSession {
            $lockedUnit = Unit::query()->whereKey($unit->id)->lockForUpdate()->firstOrFail();

            if ($lockedUnit->activeSession()->exists()) {
                throw new UnitAlreadyActiveException("Unit {$lockedUnit->code} sedang dipakai.");
            }

            return RentalSession::create([
                'unit_id' => $lockedUnit->id,
                'opened_by' => self::kioskOperator()->id,
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'type' => SessionType::Open,
                'status' => SessionStatus::Active,
                'started_at' => now(),
                'ends_at' => null,
                'expiry_token' => (string) Str::uuid(),
                'base_amount' => 0,
                'extra_amount' => 0,
                'total_amount' => 0,
                // Diterapkan saat berhenti dari tagihan akhir (lihat
                // StopKioskOpenPlayAction).
                'voucher_code' => $voucherCode,
                // Ditagih dari saldo saat berhenti; metodenya Saldo sejak awal
                // supaya penyelesaiannya tidak pernah menabrak "method null".
                'payment_method' => PaymentMethod::Wallet,
            ]);
        });

        // Di luar transaksi: perangkat & antrean tidak boleh menahan kunci baris.
        $this->devices->powerOn($session->unit);
        // Bersihkan QR → TV kembali ke game.
        $this->devices->clearScreen($session->unit);
        SessionStarted::dispatch($session->id, $session->unit_id);

        return $session;
    }

    private static function kioskOperator(): User
    {
        return User::query()->where('role', UserRole::Owner)->orderBy('id')->firstOrFail();
    }
}
