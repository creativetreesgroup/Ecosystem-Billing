<?php

namespace App\Domain\Devices\Jobs;

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
 * Memastikan TV BENAR-BENAR menyala setelah sesi dimulai, lalu mencoba lagi
 * kalau belum.
 *
 * Ditemukan saat UAT di TCL Android TV sungguhan: Home Assistant menjawab
 * HTTP 200 untuk media_player.turn_on walaupun entity-nya "unavailable" dan
 * TV tidak bergerak sama sekali. Jawaban sukses dari HA tidak membuktikan
 * apa pun — tanpa verifikasi ini, sesi berjalan, pelanggan ditagih, dan
 * layarnya tetap gelap tanpa ada yang tahu.
 *
 * Job ini TIDAK PERNAH tidur menunggu. Versi pertamanya memakai sleep() dan
 * langsung menyumbat antrean: satu percobaan menahan worker hampir satu
 * menit, sehingga enam unit yang mulai bersamaan membuat sisanya mengantre
 * di belakang perangkat yang sedang ditunggui. Sekarang tiap percobaan
 * menjadwalkan dirinya sendiri dan melepas worker.
 */
class VerifyUnitPoweredOnJob implements ShouldQueue
{
    use Queueable;

    private const MAX_ATTEMPTS = 4;

    /**
     * Jeda antar percobaan.
     *
     * Angkanya bukan tebakan. media_player.turn_on untuk Android TV mengirim
     * tombol POWER yang bersifat TOGGLE di perangkat ini: percobaan dengan
     * jeda 4 detik membuat TV menyala di detik ke-25 lalu MATI LAGI, ditekan
     * oleh perintah berikutnya. Dari standby dalam, perangkat butuh ~15 detik
     * untuk melaporkan dirinya menyala — menunggu lebih lama jauh lebih murah
     * daripada mematikan TV yang sudah benar.
     */
    private const SECONDS_BETWEEN_ATTEMPTS = 20;

    public function __construct(
        public readonly int $unitId,
        public readonly int $attempt = 1,
    ) {}

    public function handle(DeviceManager $devices): void
    {
        $unit = Unit::find($this->unitId);

        // Kasir bisa menutup sesi selagi kita menunggu. Memaksa TV menyala
        // setelah itu meninggalkan unit kosong dengan layar hidup.
        if (! $unit?->activeSession) {
            return;
        }

        try {
            if ($devices->driverFor($unit)->state($unit) === PowerState::On) {
                return;
            }
        } catch (Throwable $e) {
            Log::warning('Status TV tidak bisa dibaca saat verifikasi nyala.', [
                'unit_id' => $unit->id,
                'attempt' => $this->attempt,
                'error' => $e->getMessage(),
            ]);
        }

        if ($this->attempt >= self::MAX_ATTEMPTS) {
            DeviceAlert::raiseOnce(
                $unit->id,
                DeviceAlertType::PowerOnFailed,
                "TV unit {$unit->code} tidak menyala walau sesi sudah berjalan — nyalakan manual dan cek perangkatnya.",
            );

            return;
        }

        // attempt(), bukan powerOn(): powerOn() menjadwalkan verifikasi baru
        // dan akan melipatgandakan job ini setiap putaran.
        $devices->attempt($unit, fn ($driver) => $driver->powerOn($unit));

        self::dispatch($unit->id, $this->attempt + 1)
            ->delay(now()->addSeconds(self::SECONDS_BETWEEN_ATTEMPTS));
    }
}
