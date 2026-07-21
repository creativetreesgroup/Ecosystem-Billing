<?php

namespace App\Console\Commands;

use App\Domain\Devices\DeviceManager;
use App\Models\Unit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Tampilkan QR di semua TV yang menganggur — dipakai saat outlet BUKA, supaya
 * setiap TV langsung mengundang pelanggan memindai tanpa harus menunggu satu
 * sesi berjalan lebih dulu.
 *
 * Unit yang sedang dipakai dilewati: menimpanya dengan QR di tengah permainan
 * justru menutupi game pelanggan yang sudah membayar.
 */
#[Signature('tv:show-idle {--unit= : Batasi ke satu kode unit}')]
#[Description('Tampilkan QR menganggur di semua TV yang sedang tidak dipakai')]
class ShowTvIdleScreens extends Command
{
    public function handle(DeviceManager $devices): int
    {
        $units = Unit::query()
            ->where('is_active', true)
            ->when($this->option('unit'), fn ($query, $code) => $query->where('code', $code))
            ->whereDoesntHave('activeSession')
            ->get();

        foreach ($units as $unit) {
            $devices->showIdleScreen($unit);
            $this->line("QR ditampilkan di {$unit->code}");
        }

        $this->info("Selesai — {$units->count()} TV menganggur menampilkan QR.");

        return self::SUCCESS;
    }
}
