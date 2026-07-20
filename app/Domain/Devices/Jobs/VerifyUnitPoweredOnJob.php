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
 * HTTP 200 untuk media_player.turn_on walaupun entity-nya sedang
 * "unavailable" dan TV tidak menyala sama sekali. Jadi jawaban sukses dari
 * HA TIDAK membuktikan apa pun — dan tanpa verifikasi ini, sesi berjalan,
 * pelanggan ditagih, dan layarnya tetap gelap.
 *
 * Penyebabnya: Android TV memutus koneksi remote saat standby. Wake-on-LAN
 * membangunkan chip jaringannya sehingga entity kembali available dalam
 * beberapa detik, BARU setelah itu turn_on bisa diterima. Urutan itu yang
 * diulang di sini — terbukti berhasil pada putaran ketiga di perangkat asli.
 *
 * Dijalankan di antrean, bukan di dalam transaksi billing: sesi tidak boleh
 * menunggu perangkat (prinsip arsitektur #1).
 */
class VerifyUnitPoweredOnJob implements ShouldQueue
{
    use Queueable;

    private const MAX_ATTEMPTS = 5;

    private const SECONDS_BETWEEN_ATTEMPTS = 4;

    public function __construct(public readonly int $unitId) {}

    public function handle(DeviceManager $devices): void
    {
        $unit = Unit::find($this->unitId);

        if (! $unit?->activeSession) {
            // Sesi sudah ditutup lebih dulu — memaksa TV menyala sekarang
            // justru meninggalkan unit kosong dengan layar hidup.
            return;
        }

        $driver = $devices->driverFor($unit);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                if ($driver->state($unit) === PowerState::On) {
                    return;
                }

                $driver->powerOn($unit);
            } catch (Throwable $e) {
                Log::warning('Percobaan menyalakan TV gagal.', [
                    'unit_id' => $unit->id,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }

            sleep(self::SECONDS_BETWEEN_ATTEMPTS);
        }

        try {
            if ($driver->state($unit) === PowerState::On) {
                return;
            }
        } catch (Throwable) {
            // Tidak bisa dipastikan = laporkan (prinsip arsitektur #5).
        }

        DeviceAlert::raiseOnce(
            $unit->id,
            DeviceAlertType::PowerOnFailed,
            "TV unit {$unit->code} tidak menyala walau sesi sudah berjalan — nyalakan manual dan cek perangkatnya.",
        );
    }
}
