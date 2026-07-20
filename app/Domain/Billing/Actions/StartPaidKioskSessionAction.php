<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Devices\DeviceManager;
use App\Domain\Sessions\Events\SessionStarted;
use App\Domain\Sessions\Jobs\ExpireRentalSession;
use App\Domain\Sessions\Jobs\WarnSessionEnding;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Settings\SettingKey;
use App\Models\Payment;
use App\Models\RentalSession;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Menjalankan sesi kios setelah pembayarannya BENAR-BENAR lunas.
 *
 * Inilah yang membuat kios tanpa kasir tetap aman: uang lebih dulu, waktu
 * belakangan. Dipanggil dari dua arah — QRIS yang lunas menurut gateway, dan
 * bukti transfer yang diterima kasir — jadi harus aman dipanggil berkali-kali
 * untuk pembayaran yang sama.
 */
class StartPaidKioskSessionAction
{
    public function __construct(private readonly DeviceManager $devices) {}

    public function handle(Payment $payment): ?RentalSession
    {
        if (! $payment->isSettled()) {
            return null;
        }

        $started = DB::transaction(function () use ($payment): ?RentalSession {
            $session = RentalSession::query()
                ->whereKey($payment->rental_session_id)
                ->lockForUpdate()
                ->first();

            // Hanya sesi yang memang masih menunggu. Pembayaran yang datang
            // untuk sesi yang sudah berjalan, sudah selesai, atau sudah
            // dibatalkan tidak boleh menghidupkannya kembali.
            if ($session?->status !== SessionStatus::Pending) {
                return null;
            }

            $startedAt = now();

            $session->update([
                'status' => SessionStatus::Active,
                'started_at' => $startedAt,
                'ends_at' => $session->package
                    ? $startedAt->copy()->addMinutes($session->package->duration_minutes)
                    : null,
                'paid_at' => $payment->verified_at ?? $startedAt,
            ]);

            activity()
                ->performedOn($session)
                ->withProperties(['payment_id' => $payment->id, 'method' => $payment->method->value])
                ->event('kiosk_session_started')
                ->log('Sesi kios dimulai setelah pembayaran lunas');

            return $session->fresh();
        });

        if (! $started) {
            return null;
        }

        // Di luar transaksi: perangkat & antrean tidak boleh menahan kunci
        // baris, dan kegagalannya tidak boleh membatalkan pembayaran yang
        // uangnya sudah masuk (prinsip arsitektur #1).
        $this->devices->powerOn($started->unit);

        if ($started->ends_at) {
            $warning = (int) Setting::get(SettingKey::WarningBeforeMinutes);

            ExpireRentalSession::dispatch($started->id, $started->expiry_token)->delay($started->ends_at);
            WarnSessionEnding::dispatch($started->id, $started->expiry_token)
                ->delay($started->ends_at->copy()->subMinutes($warning));
        }

        SessionStarted::dispatch($started->id, $started->unit_id);

        return $started;
    }
}
