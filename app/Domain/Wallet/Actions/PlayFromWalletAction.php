<?php

namespace App\Domain\Wallet\Actions;

use App\Domain\Billing\PaymentMethod;
use App\Domain\Devices\DeviceManager;
use App\Domain\Discounts\DiscountEngine;
use App\Domain\Discounts\DiscountTarget;
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
        private readonly DiscountEngine $discounts,
    ) {}

    public function handle(Customer $customer, Unit $unit, Package $package, ?string $voucherCode = null): RentalSession
    {
        if ($package->unit_type_id !== $unit->unit_type_id) {
            throw new InvalidArgumentException('Paket ini tidak berlaku untuk tipe unit tersebut.');
        }

        if (! $package->is_active || ! $unit->is_active || ! $customer->is_active) {
            throw new InvalidArgumentException('Unit, paket, atau akun sedang tidak tersedia.');
        }

        // Pratinjau diskon lebih dulu supaya pesan afford & galat voucher ramah:
        // voucher bila diberi (melempar bila salah), selain itu promo otomatis
        // terbaik. Penjaga sesungguhnya (kuota di bawah kunci, penjaga saldo)
        // tetap di dalam transaksi.
        $previewDiscount = 0;

        if ($voucherCode) {
            $previewDiscount = $this->discounts->preview($voucherCode, DiscountTarget::Package, $package->price, $customer)->discount;
        } elseif ($promo = $this->discounts->bestPromo(DiscountTarget::Package, $package->price, $customer)) {
            $previewDiscount = $promo->type->discountOn($package->price, $promo->value);
        }

        if (! $customer->canAfford($package->price - $previewDiscount)) {
            throw new InsufficientBalanceException('Saldo belum cukup untuk paket ini.');
        }

        $session = DB::transaction(function () use ($customer, $unit, $package, $voucherCode): RentalSession {
            $lockedUnit = Unit::query()->whereKey($unit->id)->lockForUpdate()->firstOrFail();

            if ($lockedUnit->activeSession()->exists()) {
                throw new UnitAlreadyActiveException("Unit {$lockedUnit->code} sedang dipakai.");
            }

            $startedAt = now();

            $session = RentalSession::create([
                'unit_id' => $lockedUnit->id,
                'opened_by' => User::kioskOperator()->id,
                'customer_id' => $customer->id,
                'package_id' => $package->id,
                'customer_name' => $customer->name,
                'type' => SessionType::Package,
                'status' => SessionStatus::Active,
                'started_at' => $startedAt,
                'ends_at' => $startedAt->copy()->addMinutes($package->duration_minutes),
                'expiry_token' => (string) Str::uuid(),
                // base_amount = harga list; total_amount = setelah diskon. Selisih
                // keduanya adalah potongan, dan barisnya tercatat di
                // discount_redemptions untuk rekonsiliasi.
                'base_amount' => $package->price,
                'extra_amount' => 0,
                'total_amount' => $package->price,
                // Wajib diisi: tanpa ini job expiry menabrak "method cannot be
                // null" saat mencatat pembayaran penyelesaian, dan unitnya macet
                // dianggap terpakai selamanya. Wallet, bukan tunai — uangnya
                // sudah masuk laci saat isi saldo.
                'payment_method' => PaymentMethod::Wallet,
                'paid_at' => $startedAt,
            ]);

            // Terapkan diskon DI DALAM transaksi (voucher atau promo otomatis):
            // mengunci baris diskon & memvalidasi ulang kuota, lalu memakai
            // nominal AUTORITATIF-nya. discount_amount disimpan supaya total tetap
            // konsisten saat penyelesaian (SessionTotal menguranginya).
            $charge = $package->price;

            $redemption = $this->discounts->apply(
                $voucherCode,
                DiscountTarget::Package,
                $package->price,
                $customer,
                ['rental_session_id' => $session->id],
            );

            if ($redemption) {
                $charge = $package->price - $redemption->amount;
                $session->update([
                    'discount_amount' => $redemption->amount,
                    'voucher_code' => $voucherCode,
                    'total_amount' => $charge,
                ]);
            }

            $this->wallet->spend($customer, $charge, $session);

            return $session;
        });

        // Di luar transaksi: perangkat & antrean tidak boleh menahan kunci
        // baris, dan kegagalannya tidak boleh membatalkan saldo yang sudah
        // terpotong untuk sesi yang sah (prinsip arsitektur #1).
        $this->devices->powerOn($session->unit);
        // Bersihkan QR → TV kembali ke game (satset, sinkron).
        $this->devices->clearScreen($session->unit);

        $warning = (int) Setting::get(SettingKey::WarningBeforeMinutes);
        ExpireRentalSession::dispatch($session->id, $session->expiry_token)->delay($session->ends_at);
        WarnSessionEnding::dispatch($session->id, $session->expiry_token)
            ->delay($session->ends_at->copy()->subMinutes($warning));

        SessionStarted::dispatch($session->id, $session->unit_id);

        return $session;
    }
}
