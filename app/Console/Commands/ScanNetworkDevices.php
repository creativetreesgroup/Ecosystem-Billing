<?php

namespace App\Console\Commands;

use App\Domain\Devices\DiscoveredDevice;
use App\Domain\Devices\NetworkScanner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Alat diagnosis untuk pertanyaan paling sering di lapangan: "TV-nya benar
 * terhubung ke WiFi kita atau tidak?"
 *
 * Sengaja tersedia dari terminal, bukan cuma dari panel: saat panel sendiri
 * bermasalah atau Home Assistant belum dipasang, ini yang bisa dijalankan
 * teknisi langsung di mesin outlet.
 */
#[Signature('devices:scan {--all : Tampilkan semua perangkat, bukan hanya yang terlihat seperti TV} {--timeout=4 : Lama menunggu balasan, dalam detik}')]
#[Description('Pindai jaringan lokal untuk menemukan TV yang terhubung (SSDP/UPnP)')]
class ScanNetworkDevices extends Command
{
    public function handle(NetworkScanner $scanner): int
    {
        $timeout = max(1, (int) $this->option('timeout'));

        $this->info("Memindai jaringan selama {$timeout} detik...");

        $devices = $this->option('all')
            ? $scanner->scan($timeout)
            : $scanner->scanTelevisions($timeout);

        if ($devices === []) {
            $this->warn('Tidak ada perangkat yang menjawab.');
            $this->newLine();
            $this->line('Yang perlu dicek, berurutan:');
            $this->line('  1. Mesin ini dan TV berada di jaringan/VLAN yang SAMA.');
            $this->line('  2. Isolasi klien (AP isolation) di router dimatikan.');
            $this->line('  3. TV menyala, atau standby dengan jaringan tetap aktif.');
            $this->line('  4. Di macOS, aplikasi terminal sudah diberi izin "Local Network".');
            $this->newLine();
            $this->line('Coba juga dengan --all: perangkatnya mungkin terdeteksi, hanya tidak dikenali sebagai TV.');

            return self::FAILURE;
        }

        $this->table(
            ['IP', 'Nama', 'Model / software', 'Merek', 'TV?'],
            array_map(fn (DiscoveredDevice $device): array => [
                $device->ip,
                $device->name ?? '-',
                $device->model ?? $device->server ?? '-',
                $device->manufacturer ?? '-',
                $device->looksLikeTelevision() ? 'ya' : '-',
            ], $devices),
        );

        $this->info(count($devices).' perangkat ditemukan.');

        return self::SUCCESS;
    }
}
