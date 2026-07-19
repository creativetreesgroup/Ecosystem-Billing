<?php

namespace App\Console\Commands;

use App\Domain\Devices\DeviceManager;
use App\Models\Unit;
use Illuminate\Console\Command;

/**
 * Menampilkan semua TV yang ditemukan Home Assistant di jaringan yang sama,
 * dan menandai mana yang belum dipasangkan ke unit mana pun. Dipakai saat
 * memasang unit baru — lihat RUNBOOK.md "Prosedur menambah unit baru".
 */
class DiscoverUnits extends Command
{
    protected $signature = 'units:discover';

    protected $description = 'Cari TV di jaringan lewat Home Assistant dan tampilkan mana yang belum terpasang ke unit.';

    public function handle(DeviceManager $devices): int
    {
        $discovered = $devices->homeAssistant()->discoverMediaPlayers();

        if ($discovered === []) {
            $this->warn('Tidak ada TV terdeteksi. Cek Home Assistant hidup, HA_TOKEN benar, dan TV menyala di jaringan yang sama.');

            return self::SUCCESS;
        }

        $taken = Unit::query()->whereNotNull('control_ref')->pluck('code', 'control_ref');

        $this->table(
            ['Entity ID', 'Nama di HA', 'Status'],
            collect($discovered)->map(fn (string $name, string $entityId) => [
                $entityId,
                $name,
                isset($taken[$entityId]) ? "dipakai unit {$taken[$entityId]}" : 'BELUM dipakai',
            ])->values()->all(),
        );

        $free = collect($discovered)->keys()->reject(fn (string $id) => isset($taken[$id]))->count();
        $this->info("{$free} TV terdeteksi belum dipasangkan ke unit mana pun.");

        return self::SUCCESS;
    }
}
