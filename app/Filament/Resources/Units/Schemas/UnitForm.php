<?php

namespace App\Filament\Resources\Units\Schemas;

use App\Domain\Devices\Capability;
use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\DeviceManager;
use App\Models\Unit;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class UnitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('outlet_id')
                    ->label('Outlet')
                    ->relationship('outlet', 'name')
                    ->required(),
                Select::make('unit_type_id')
                    ->label('Tipe unit')
                    ->relationship('unitType', 'name')
                    ->required(),
                TextInput::make('code')
                    ->label('Kode unit')
                    ->placeholder('mis. PS-01')
                    ->required(),
                Select::make('control_driver')
                    ->label('Driver kontrol TV')
                    ->options(ControlDriver::class)
                    ->native(false)
                    ->required()
                    ->live(),
                // Deteksi otomatis: Home Assistant sudah menemukan sendiri semua
                // TV di WiFi/LAN yang sama lewat mDNS/SSDP, jadi operator tidak
                // perlu tahu (apalagi mengetik) entity_id maupun IP-nya — cukup
                // pilih dari daftar. Field ini TIDAK disimpan (dehydrated(false));
                // ia hanya mengisi control_ref di bawahnya, supaya control_ref
                // tetap satu-satunya sumber kebenaran dan tetap bisa dikoreksi
                // manual kalau perlu.
                Select::make('discovered_tv')
                    ->label('TV terdeteksi di jaringan')
                    ->options(fn (?Unit $record): array => self::availableTvs($record))
                    ->searchable()
                    ->native(false)
                    ->live()
                    ->dehydrated(false)
                    ->placeholder('Pilih TV yang terdeteksi')
                    ->helperText('Dipindai langsung dari Home Assistant. TV yang sudah dipakai unit lain, atau yang mati / tidak satu jaringan, tidak muncul di daftar ini.')
                    ->afterStateUpdated(fn (?string $state, Set $set) => filled($state) ? $set('control_ref', $state) : null)
                    ->visible(fn (Get $get) => self::driverOf($get('control_driver')) === ControlDriver::HomeAssistant),
                TextInput::make('control_ref')
                    ->label('Referensi kontrol')
                    ->helperText('Entity ID Home Assistant (terisi otomatis dari pilihan di atas), atau topic Tasmota seperti plug-ps01.')
                    ->required(fn (Get $get) => self::driverOf($get('control_driver')) !== ControlDriver::Manual)
                    ->visible(fn (Get $get) => self::driverOf($get('control_driver')) !== ControlDriver::Manual)
                    // Filament TIDAK menyimpan field yang sedang tersembunyi.
                    // Tanpa dua baris ini, mengubah unit ke driver Manual
                    // meninggalkan control_ref lama di DB — dan karena ada unique
                    // index, TV itu terkunci selamanya: tidak dipakai unit ini
                    // (driver-nya manual) tapi juga tidak bisa dipakai unit lain.
                    // dehydratedWhenHidden(), BUKAN dehydrated(): untuk field yang
                    // sedang disembunyikan, dehydrated() saja tidak berlaku —
                    // isDehydrated() lebih dulu gugur lewat
                    // isHiddenAndNotDehydratedWhenHidden().
                    ->dehydratedWhenHidden()
                    ->dehydrateStateUsing(fn (Get $get, ?string $state) => self::driverOf($get('control_driver')) === ControlDriver::Manual ? null : $state)
                    // Cermin dari unique index uq_unit_control_ref di DB: dua unit
                    // menunjuk perangkat yang sama berarti menutup sesi di satu unit
                    // ikut mematikan TV unit lain yang masih dipakai & ditagih.
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('outlet_id', $get('outlet_id')),
                    )
                    ->validationMessages(['unique' => 'Perangkat ini sudah dipakai unit lain.']),
                TextInput::make('tv_mac')
                    ->label('MAC address TV')
                    ->helperText('Untuk Wake-on-LAN (opsional).'),
                Select::make('capabilities')
                    ->label('Kapabilitas')
                    ->multiple()
                    ->options(Capability::class)
                    ->native(false),
                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true)
                    ->required(),
                Textarea::make('notes')
                    ->label('Catatan')
                    ->columnSpanFull(),
            ]);
    }

    /**
     * `$get('control_driver')` bisa berupa string (state mentah dari browser)
     * ATAU instance ControlDriver (setelah Filament meng-cast state Select
     * ber-enum). Membandingkan langsung ke ->value diam-diam selalu salah
     * untuk bentuk yang satunya — dinormalkan di sini supaya kedua bentuk aman.
     */
    private static function driverOf(mixed $state): ?ControlDriver
    {
        return $state instanceof ControlDriver ? $state : ControlDriver::tryFrom((string) $state);
    }

    /**
     * TV hasil discovery yang belum dipegang unit lain. Menyaring di sumbernya
     * jauh lebih baik daripada menolak setelah disubmit: operator tidak pernah
     * melihat pilihan yang akan gagal. Unit yang sedang diedit tetap boleh
     * melihat perangkatnya sendiri supaya tidak hilang dari daftar.
     *
     * @return array<string, string>
     */
    private static function availableTvs(?Unit $record): array
    {
        $taken = Unit::query()
            ->whereNotNull('control_ref')
            ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
            ->pluck('control_ref')
            ->all();

        return array_diff_key(
            app(DeviceManager::class)->homeAssistant()->discoverMediaPlayers(),
            array_flip($taken),
        );
    }
}
