<?php

namespace App\Domain\Devices\Jobs;

use App\Domain\Devices\DeviceAlertType;
use App\Domain\Devices\DeviceManager;
use App\Domain\Devices\PowerState;
use App\Domain\Sessions\Actions\VoidSessionAction;
use App\Models\DeviceAlert;
use App\Models\Unit;
use App\Models\User;
use App\Models\WalletTransaction;
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

        $state = PowerState::Unknown;

        try {
            $state = $devices->driverFor($unit)->state($unit);

            if ($state === PowerState::On) {
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

            // Standby = perangkatnya SENDIRI memastikan dirinya tidak menyala.
            // Hanya itu yang boleh menggerakkan uang. Unreachable/Unknown berarti
            // kita tidak tahu apa-apa: pelanggan bisa saja sedang asyik bermain
            // sementara jaringan ke Home Assistant yang putus, dan mengembalikan
            // saldo di situ sama dengan membagikan sesi gratis. Sama seperti
            // aturan Batalkan saat gateway QRIS tak terjangkau: tidak tahu =
            // jangan sentuh uangnya, panggil manusia.
            if ($state === PowerState::Standby) {
                $this->refundUnplayedSession($unit);
            }

            return;
        }

        // attempt(), bukan powerOn(): powerOn() menjadwalkan verifikasi baru
        // dan akan melipatgandakan job ini setiap putaran.
        $devices->attempt($unit, fn ($driver) => $driver->powerOn($unit));

        self::dispatch($unit->id, $this->attempt + 1)
            ->delay(now()->addSeconds(self::SECONDS_BETWEEN_ATTEMPTS));
    }

    /**
     * Sesi yang sudah dibayar tapi TV-nya terbukti tak pernah menyala: batalkan
     * dan kembalikan saldonya.
     *
     * Sebelum ini alert untuk staf adalah satu-satunya akibat — jam terus
     * berjalan di layar gelap dan uang pelanggan tertahan sampai ada manusia
     * yang kebetulan menyadarinya. Alert-nya tetap ada (perangkatnya memang
     * rusak dan harus diperiksa); yang ditambahkan di sini cuma satu hal:
     * uangnya tidak ikut menunggu.
     *
     * Dibatasi ke sesi yang benar-benar memotong dompet. Sesi tunai uangnya ada
     * di laci, jadi tak ada yang bisa dikembalikan otomatis — void-nya keputusan
     * kasir, bukan job ini.
     */
    private function refundUnplayedSession(Unit $unit): void
    {
        $session = $unit->activeSession;

        $charged = -(int) WalletTransaction::query()
            ->where('rental_session_id', $session->id)
            ->sum('amount');

        if ($charged <= 0) {
            return;
        }

        try {
            app(VoidSessionAction::class)->handle(
                $session,
                User::kioskOperator(),
                "Otomatis: TV unit {$unit->code} tidak menyala, saldo dikembalikan.",
            );
        } catch (Throwable $e) {
            // Gagal mengembalikan bukan alasan job ini gagal keras: alert-nya
            // sudah dibuat, jadi stafnya tetap tahu. Jejaknya ditinggalkan di
            // log supaya bisa ditelusuri, dan wallet:audit tetap menjaga
            // konsistensi saldo.
            Log::error('Gagal mengembalikan saldo sesi yang TV-nya tidak menyala.', [
                'unit_id' => $unit->id,
                'rental_session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
