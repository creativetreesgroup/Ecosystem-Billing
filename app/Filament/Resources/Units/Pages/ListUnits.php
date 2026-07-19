<?php

namespace App\Filament\Resources\Units\Pages;

use App\Domain\Devices\DiscoveredDevice;
use App\Domain\Devices\NetworkScanner;
use App\Filament\Resources\Units\UnitResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class ListUnits extends ListRecords
{
    protected static string $resource = UnitResource::class;

    /**
     * Hasil pemindaian disimpan di properti, bukan dihitung ulang di dalam
     * schema: modal Filament merender schemanya lebih dari sekali, dan
     * pemindaian ini memakan waktu nyata di jaringan (beberapa detik).
     *
     * @var array<int, array{ip: string, label: string}>
     */
    public array $scanResults = [];

    protected function getHeaderActions(): array
    {
        return [
            $this->scanNetworkAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Menjawab pertanyaan yang selama ini tidak bisa dijawab sistem sendiri:
     * "TV-nya benar terhubung ke jaringan kita atau tidak?"
     *
     * Ini SENGAJA hanya diagnosis, bukan jalur pemasangan. Perangkat yang
     * ditemukan di sini tidak bisa langsung dijadikan control_ref karena SSDP
     * tidak memberi jalur kontrol yang seragam antar merek — pemasangan tetap
     * lewat daftar "TV terdeteksi" dari Home Assistant di form unit.
     *
     * Bedanya penting saat ada masalah: kalau perangkatnya muncul DI SINI tapi
     * tidak muncul di daftar Home Assistant, yang bermasalah HA-nya — bukan
     * TV-nya, bukan kabelnya, bukan WiFi-nya.
     */
    protected function scanNetworkAction(): Action
    {
        return Action::make('scanNetwork')
            ->label('Pindai jaringan')
            ->icon(Heroicon::OutlinedSignal)
            ->color('gray')
            // Bentuknya mengikuti modal konfirmasi bawaan Filament: sempit,
            // rata tengah, ikon di atas. Hasil pemindaian adalah jawaban ya/
            // tidak atas satu pertanyaan — bukan halaman data yang perlu
            // dibaca menyamping.
            ->modalWidth(Width::Medium)
            ->modalAlignment(Alignment::Center)
            ->modalFooterActionsAlignment(Alignment::Center)
            ->modalIcon(fn (): string => $this->scanResults === []
                ? 'heroicon-o-signal-slash'
                : 'heroicon-o-signal')
            ->modalIconColor(fn (): string => $this->scanResults === [] ? 'warning' : 'success')
            ->modalHeading(fn (): string => $this->scanResults === []
                ? 'Tidak ada TV yang menjawab'
                : count($this->scanResults).' TV di jaringan outlet')
            ->modalDescription(fn (): string => $this->scanResults === []
                ? 'Periksa berurutan: mesin ini & TV satu jaringan · isolasi klien (AP isolation) di router mati · TV menyala atau standby dengan jaringan aktif.'
                : 'Dipindai langsung dari mesin ini, tanpa lewat Home Assistant.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Tutup')
            ->mountUsing(function (NetworkScanner $scanner): void {
                $this->scanResults = array_map(fn (DiscoveredDevice $device): array => [
                    'ip' => $device->ip,
                    // Merek dari deskripsi UPnP TIDAK dipakai: TCL Smart TV di
                    // jaringan uji melaporkan dirinya "Microsoft Corporation"
                    // karena yang menjawab adalah renderer DLNA-nya, bukan
                    // TV-nya. Nama ramah + IP sudah cukup untuk mencocokkan
                    // dengan TV di ruangan, dan keduanya tidak menyesatkan.
                    'label' => $device->name ?? $device->model ?? 'Perangkat tanpa nama',
                ], $scanner->scanTelevisions());
            })
            // Satu baris per perangkat: "TCL Smart TV (192.168.100.7)".
            // BUKAN helperText di bawahnya — helper text di Filament selalu
            // rata kiri dan tidak bisa ditengahkan, sehingga merusak sumbu
            // tengah modal. Yang disalin tetap IP-nya saja, karena itu yang
            // ditempel operator saat mengatur Home Assistant.
            ->schema(fn (): array => array_map(
                fn (array $device): TextEntry => TextEntry::make('tv_'.str_replace('.', '_', $device['ip']))
                    ->hiddenLabel()
                    ->alignCenter()
                    ->state($device['label'].' ('.$device['ip'].')')
                    ->weight(FontWeight::Bold)
                    ->copyable()
                    ->copyableState($device['ip'])
                    ->copyMessage('IP disalin'),
                $this->scanResults,
            ));
    }
}
