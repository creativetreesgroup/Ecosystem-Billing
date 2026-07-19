<?php

namespace App\Filament\Resources\Units\Schemas;

use App\Domain\Devices\Capability;
use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\DeviceManager;
use App\Domain\Devices\NetworkScanner;
use App\Models\Unit;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rules\Unique;

class UnitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas unit')
                    // ['md' => 2], bukan columns(2) — columns(2) di Filament
                    // berarti ['lg' => 2] dan tablet ikut menumpuk seperti HP.
                    ->columns(['md' => 2])
                    ->schema([
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
                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->helperText('Unit nonaktif tidak muncul di dasbor kasir.')
                            ->default(true)
                            ->required(),
                    ]),

                Section::make('Kontrol TV')
                    ->description('Menentukan bagaimana sistem menyalakan & mematikan TV unit ini.')
                    // ['md' => 2], bukan columns(2) — columns(2) di Filament
                    // berarti ['lg' => 2] dan tablet ikut menumpuk seperti HP.
                    ->columns(['md' => 2])
                    ->schema([
                        Select::make('control_driver')
                            ->label('Driver kontrol TV')
                            ->options(ControlDriver::class)
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
                            ->live()
                            ->dehydrated(false)
                            ->placeholder('Pilih TV yang terdeteksi')
                            // Daftar kosong punya TIGA sebab yang sangat
                            // berbeda, dan pesan bawaan Filament ("Tidak ada
                            // pilihan yang tersedia") menyamakan ketiganya —
                            // terbaca seperti "tidak ada TV di jaringan"
                            // padahal yang belum ada justru tokennya.
                            ->helperText(fn (?Unit $record): string => match (true) {
                                ! app(DeviceManager::class)->homeAssistant()->isConfigured() => 'Home Assistant belum diatur (HA_BASE_URL / HA_TOKEN kosong di .env), jadi daftar ini pasti kosong. Sistem tetap bisa membuktikan TV-nya ada di jaringan lewat tombol pindai di kolom MAC.',
                                self::availableTvs($record) === [] => 'Home Assistant terhubung, tapi belum ada entity media_player yang bebas. TV yang mati, tidak satu jaringan, atau sudah dipakai unit lain tidak muncul di sini.',
                                default => 'Dipindai langsung dari Home Assistant. TV yang sudah dipakai unit lain, atau yang mati / tidak satu jaringan, tidak muncul di daftar ini.',
                            })
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
                            ->placeholder('aa:bb:cc:dd:ee:ff')
                            ->helperText('Untuk Wake-on-LAN (opsional). Tekan ikon di kanan untuk memindai jaringan.')
                            ->suffixAction(self::pickMacFromNetworkAction())
                            // Diseragamkan SEBELUM disimpan: MAC yang disalin
                            // dari halaman router sering kehilangan nol di depan
                            // ("2f:6" alih-alih "2f:06"). WakeOnLan menuntut
                            // tepat 12 digit heksa dan menolak bentuk pendek itu
                            // DALAM DIAM — TV tidak pernah bangun, dan tidak ada
                            // pesan apa pun yang menjelaskan kenapa.
                            ->dehydrateStateUsing(fn (?string $state) => filled($state)
                                ? NetworkScanner::normaliseMac($state)
                                : null)
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if (filled($value) && NetworkScanner::normaliseMac((string) $value) === null) {
                                    $fail('Format MAC tidak dikenali. Contoh yang benar: aa:bb:cc:dd:ee:ff');
                                }
                            }),
                        Select::make('capabilities')
                            ->label('Kapabilitas')
                            ->multiple()
                            ->options(Capability::class),
                    ]),

                Section::make('Catatan')
                    ->collapsed()
                    ->schema([
                        Textarea::make('notes')
                            ->hiddenLabel()
                            ->placeholder('Catatan internal — mis. remote hilang, HDMI 2 rusak.')
                            ->rows(3),
                    ]),
            ]);
    }

    /**
     * Memindai jaringan lalu mengisi tv_mac dari TV yang dipilih.
     *
     * MAC tidak pernah tertulis di badan TV dan tidak muncul di menu mana pun
     * yang mudah dijangkau — operator harus menggali halaman admin router.
     * Salah satu digit saja membuat Wake-on-LAN gagal dalam diam.
     *
     * Yang diisi HANYA tv_mac, bukan control_ref: SSDP memberi IP, sedangkan
     * control_ref harus berupa entity_id Home Assistant atau topic Tasmota.
     * Mengisikan IP ke sana akan membuat unitnya tampak terpasang padahal
     * tidak pernah bisa dikontrol.
     */
    private static function pickMacFromNetworkAction(): Action
    {
        return Action::make('scanForMac')
            ->label('Pindai jaringan')
            ->icon(Heroicon::OutlinedSignal)
            ->modalWidth(Width::Medium)
            ->modalAlignment(Alignment::Center)
            ->modalFooterActionsAlignment(Alignment::Center)
            ->modalIcon(Heroicon::OutlinedSignal)
            ->modalIconColor('success')
            ->modalHeading('Pilih TV di jaringan')
            ->modalDescription('Dipindai langsung dari mesin ini. Pilih TV yang dipasang di unit ini — semua yang bisa diketahui dari pilihan itu akan terisi sendiri.')
            ->modalSubmitActionLabel('Pakai TV ini')
            ->schema(fn (): array => [
                Radio::make('mac')
                    ->hiddenLabel()
                    ->options(fn (): array => app(NetworkScanner::class)->televisionOptions())
                    ->required(),
            ])
            ->action(function (array $data, Set $set, ?Unit $record): void {
                $picked = app(NetworkScanner::class)->televisionsForPicker()[$data['mac']] ?? null;

                $set('tv_mac', $data['mac']);

                $entity = self::matchingHomeAssistantEntity($picked['name'] ?? null, $record);

                if ($entity !== null) {
                    $set('control_ref', $entity);
                    $set('discovered_tv', $entity);
                }

                Notification::make()
                    ->title($entity !== null ? 'TV terpasang otomatis' : 'MAC terisi')
                    ->body($entity !== null
                        ? "MAC dan referensi kontrol ({$entity}) terisi dari TV yang dipilih."
                        : 'MAC terisi. Referensi kontrol tidak bisa ditebak dari pemindaian jaringan — pilih entity-nya dari daftar "TV terdeteksi di jaringan" (butuh Home Assistant).')
                    ->color($entity !== null ? 'success' : 'warning')
                    ->send();
            });
    }

    /**
     * Entity Home Assistant untuk TV hasil pindai — HANYA kalau kecocokannya
     * tidak meragukan.
     *
     * Pemindaian jaringan memberi IP & MAC, bukan entity_id, jadi control_ref
     * tidak bisa diturunkan darinya. Satu-satunya jembatan yang jujur adalah
     * nama: kalau TV menyebut dirinya "TCL Smart TV" dan di Home Assistant ada
     * TEPAT SATU media_player dengan nama yang sama, keduanya pasti perangkat
     * yang sama.
     *
     * Kalau ada dua yang mirip, atau tidak ada yang sama persis, sengaja tidak
     * diisi. Menebak di sini berarti unit bisa mengendalikan TV milik unit
     * lain — dan itu baru ketahuan setelah pelanggan duduk di depan layar yang
     * tiba-tiba mati.
     */
    private static function matchingHomeAssistantEntity(?string $scannedName, ?Unit $record): ?string
    {
        if (blank($scannedName)) {
            return null;
        }

        $needle = mb_strtolower(trim($scannedName));

        $matches = array_keys(array_filter(
            self::availableTvs($record),
            fn (string $friendlyName) => mb_strtolower(trim($friendlyName)) === $needle,
        ));

        return count($matches) === 1 ? $matches[0] : null;
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
