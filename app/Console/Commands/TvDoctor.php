<?php

namespace App\Console\Commands;

use App\Domain\Devices\CommandResult;
use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\DeviceManager;
use App\Domain\Devices\PowerState;
use App\Domain\Kiosk\UnitKioskScreen;
use App\Models\Unit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pemeriksaan rantai kontrol TV dari ujung ke ujung, di depan TV sungguhan.
 *
 * "Uji koneksi TV" di panel hanya membaca state — ia menjawab "TV menjawab",
 * bukan "QR benar-benar muncul di layar". Dua kegagalan paling sering di
 * pemasangan baru justru lolos dari pembacaan state itu:
 *
 * 1. APP_URL menunjuk localhost. Yang mengunduh gambar QR adalah perangkat
 *    Cast di TV, BUKAN server — "localhost" di TV berarti TV itu sendiri, jadi
 *    layarnya kosong selamanya sementara Home Assistant tetap menjawab 200.
 * 2. Ekstensi gambar PHP tidak terpasang, sehingga QR-nya tidak pernah jadi.
 *
 * Karena itu keduanya diperiksa lebih dulu, sekali, sebelum menyentuh TV mana
 * pun. Sisanya per unit dan tidak merusak: unit yang sedang dipakai pelanggan
 * selalu dilewati, dan uji nyala/mati harus diminta eksplisit lewat --power.
 */
#[Signature('tv:doctor
    {--unit= : Batasi ke satu kode unit}
    {--power : Ikut menguji nyala & mati — TV akan benar-benar hidup lalu standby}
    {--settle=8 : Detik menunggu TV bereaksi sebelum state-nya dibaca ulang}')]
#[Description('Uji rantai kontrol TV ujung ke ujung: URL QR, koneksi, cast, dan (opsional) nyala/mati')]
class TvDoctor extends Command
{
    private bool $adaGagal = false;

    public function handle(DeviceManager $devices): int
    {
        $units = Unit::query()
            ->where('is_active', true)
            ->when($this->option('unit'), fn ($query, $code) => $query->where('code', $code))
            ->orderBy('code')
            ->get();

        if ($units->isEmpty()) {
            $this->components->error('Tidak ada unit aktif yang cocok.');

            return self::FAILURE;
        }

        $this->periksaGambarQr($units->first());

        foreach ($units as $unit) {
            $this->newLine();
            $this->components->info("Unit {$unit->code}");

            if ($unit->control_driver === ControlDriver::Manual) {
                $this->lapor('Driver', true, 'manual — tidak ada yang bisa diuji, dilewati');

                continue;
            }

            if ($unit->activeSession()->exists()) {
                $this->lapor('Sesi', true, 'sedang dipakai pelanggan — dilewati, tidak diganggu');

                continue;
            }

            $this->periksaUnit($devices, $unit);
        }

        $this->newLine();

        if ($this->adaGagal) {
            $this->components->error('Ada langkah yang gagal. Perbaiki dulu sebelum outlet dibuka.');

            return self::FAILURE;
        }

        $this->components->info('Semua langkah lolos.');

        return self::SUCCESS;
    }

    private function periksaUnit(DeviceManager $devices, Unit $unit): void
    {
        if (blank($unit->control_ref)) {
            $this->lapor('Entity', false, 'control_ref kosong — TV belum dipasangkan ke unit ini');

            return;
        }

        $state = $devices->attempt($unit, fn ($driver) => $driver->state($unit));

        $this->lapor(
            'Koneksi',
            $state instanceof PowerState && $state !== PowerState::Unreachable,
            $state instanceof PowerState ? $state->getLabel() : 'driver melempar galat',
        );

        if ($this->option('power')) {
            $this->ujiDaya($devices, $unit);
        }

        // Cast QR paling akhir supaya TV ditinggalkan dalam keadaan mengundang
        // pelanggan berikutnya, bukan layar kosong.
        $this->lapor('Cast QR', ...$this->hasil($devices->showIdleScreen($unit)));
        $this->line('     <fg=yellow>LIHAT TV:</> QR harus tampil sekarang. Kalau layarnya kosong, masalahnya di URL QR atau Cast — bukan di perintah ini.');
    }

    /**
     * Nyalakan lalu matikan sungguhan. Home Assistant menjawab HTTP 200 untuk
     * turn_on walau TV tidak bereaksi sama sekali (lihat DeviceManager), jadi
     * yang dipercaya di sini bukan jawaban perintahnya melainkan state yang
     * dibaca ULANG setelah TV diberi waktu bereaksi.
     */
    private function ujiDaya(DeviceManager $devices, Unit $unit): void
    {
        $devices->attempt($unit, fn ($driver) => $driver->powerOn($unit));
        $this->lapor('Nyala', ...$this->cocok($devices, $unit, PowerState::On));

        $devices->attempt($unit, fn ($driver) => $driver->powerOff($unit));
        $this->lapor('Mati', ...$this->cocok($devices, $unit, PowerState::Standby));
    }

    /**
     * @return array{bool, string}
     */
    private function cocok(DeviceManager $devices, Unit $unit, PowerState $diharapkan): array
    {
        $jeda = max(0, (int) $this->option('settle'));

        if ($jeda > 0) {
            sleep($jeda);
        }

        $state = $devices->attempt($unit, fn ($driver) => $driver->state($unit));

        return [
            $state === $diharapkan,
            $state instanceof PowerState
                ? "TV melaporkan {$state->getLabel()}, diharapkan {$diharapkan->getLabel()}"
                : 'driver melempar galat',
        ];
    }

    /**
     * URL yang di-Cast diunduh oleh TV, bukan oleh server. Kalau host-nya
     * localhost, TV mencari gambar itu pada dirinya sendiri dan tidak pernah
     * menemukannya — kegagalan paling sering dan paling membingungkan, karena
     * semua perintah tetap menjawab sukses.
     */
    private function periksaGambarQr(Unit $unit): void
    {
        $url = route('kiosk.unit.qr', ['unit' => $unit->code]);
        $host = parse_url($url, PHP_URL_HOST);

        $this->lapor(
            'URL QR',
            is_string($host) && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true),
            is_string($host) && $host !== ''
                ? "TV mengunduh dari {$url} — host '{$host}' wajib bisa dijangkau TV. Setel APP_URL ke IP LAN server."
                : "APP_URL tidak berbentuk URL yang sah: {$url}",
        );

        try {
            $bytes = strlen(UnitKioskScreen::jpegFor($unit));
            $this->lapor('Gambar QR', $bytes > 0, "{$bytes} byte");
        } catch (Throwable $e) {
            $this->lapor('Gambar QR', false, 'gagal digambar: '.$e->getMessage());
        }
    }

    /**
     * @return array{bool, string}
     */
    private function hasil(?CommandResult $result): array
    {
        return [$result?->successful === true, $result?->message ?? 'perintah tidak terkirim'];
    }

    private function lapor(string $langkah, bool $lolos, string $catatan): void
    {
        $this->adaGagal = $this->adaGagal || ! $lolos;

        $this->line(sprintf('  %s %-9s %s', $lolos ? '<fg=green>OK   </>' : '<fg=red>GAGAL</>', $langkah, $catatan));
    }
}
