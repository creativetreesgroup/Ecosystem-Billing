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
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

            // Kunci UNIT-nya juga, sama seperti start action lain. Tanpa ini,
            // antara checkout dan settle sebuah sesi lain (kasir walk-in / Open
            // Play saldo) bisa merebut unit; saat pembayaran lunas, mengaktifkan
            // sesi ini menabrak unique index active_unit_id dan melempar
            // QueryException yang tidak tertangkap — membatalkan seluruh batch
            // poll, sementara uangnya SUDAH masuk dan sesi macet Pending
            // selamanya. Mengunci unit menyerialkan pengecekan di bawah.
            Unit::query()->whereKey($session->unit_id)->lockForUpdate()->first();

            $unitTakenByOther = RentalSession::query()
                ->where('unit_id', $session->unit_id)
                ->where('status', SessionStatus::Active)
                ->whereKeyNot($session->id)
                ->exists();

            if ($unitTakenByOther) {
                // Unit sudah dipakai sesi lain. Sesi tamu ini tidak bisa jalan;
                // di-void supaya unit & antrean bersih, dan pembayarannya (uang
                // tamu lewat gateway) ditandai untuk REFUND MANUAL — tidak ada
                // dompet tamu untuk dikembalikan otomatis. Return null supaya
                // batch poll lanjut, bukan crash.
                $session->update([
                    'status' => SessionStatus::Voided,
                    'void_reason' => 'Unit sudah terpakai sesi lain saat pembayaran lunas — perlu refund manual.',
                    'ended_at' => now(),
                ]);

                Log::warning('Pembayaran kios lunas tapi unit sudah terpakai; sesi di-void, perlu refund manual.', [
                    'payment_id' => $payment->id,
                    'session_id' => $session->id,
                    'unit_id' => $session->unit_id,
                    'amount' => $payment->amount,
                ]);

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
        $this->devices->clearScreen($started->unit);

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
