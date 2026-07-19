<?php

namespace App\Domain\Devices\Jobs;

use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\DeviceAlertType;
use App\Domain\Devices\DeviceManager;
use App\Domain\Devices\PowerState;
use App\Models\DeviceAlert;
use App\Models\Unit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatch 10 detik setelah setiap powerOff() (lihat DeviceManager::powerOff()).
 * Billing tidak pernah menunggu device — ini murni verifikasi belakangan.
 * Kalau TV ternyata masih menyala, buat device_alert supaya kasir tahu harus
 * cek fisik, bukan menggagalkan atau mengulang transaksi billing yang sudah selesai.
 */
class VerifyUnitPoweredOffJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $unitId) {}

    public function handle(DeviceManager $devices): void
    {
        $unit = Unit::find($this->unitId);

        if (! $unit) {
            return;
        }

        try {
            $state = $devices->driverFor($unit)->state($unit);
        } catch (Throwable $e) {
            Log::warning('Verifikasi power-off gagal: driver tidak bisa dihubungi.', [
                'unit_id' => $unit->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // Hanya Standby yang berarti "terbukti mati". Sebelumnya alert cuma
        // dibuat saat state persis On, sehingga verifikasi ini MATI TOTAL untuk
        // unit Tasmota yang plug-nya offline (state()-nya jadi Unknown, bukan
        // On) — kasir tidak pernah diberi tahu bahwa TV mungkin masih menyala.
        // Fail loud (prinsip arsitektur #5): tidak bisa memastikan = laporkan.
        if ($state === PowerState::Standby) {
            return;
        }

        // Unit manual memang tidak pernah bisa dipastikan, dan ManualDriver
        // sudah membuat alert-nya sendiri saat powerOff() — jangan digandakan.
        if ($state !== PowerState::On && $unit->control_driver === ControlDriver::Manual) {
            return;
        }

        DeviceAlert::create([
            'unit_id' => $unit->id,
            'type' => DeviceAlertType::PowerOffFailed,
            'message' => $state === PowerState::On
                ? "TV unit {$unit->code} masih menyala 10 detik setelah perintah power-off dikirim."
                : "Status TV unit {$unit->code} tidak bisa dipastikan mati setelah perintah power-off — cek fisik.",
        ]);
    }
}
