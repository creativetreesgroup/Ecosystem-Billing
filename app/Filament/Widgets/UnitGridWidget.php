<?php

namespace App\Filament\Widgets;

use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\Rupiah;
use App\Domain\Billing\SessionTotal;
use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\DeviceAlertStatus;
use App\Domain\Devices\DeviceManager;
use App\Domain\Devices\PowerState;
use App\Domain\Sessions\Actions\CompleteSessionAction;
use App\Domain\Sessions\Actions\ExtendSessionAction;
use App\Domain\Sessions\Actions\StartSessionAction;
use App\Domain\Sessions\SessionType;
use App\Models\Package;
use App\Models\RentalSession;
use App\Models\Unit;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;

class UnitGridWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Unit';

    // Ini dashboard utama kasir, bukan widget sekunder di halaman yang sudah
    // padat — lazy loading cuma menambah round-trip tanpa manfaat nyata di sini.
    protected static bool $isLazy = false;

    /**
     * Grup ToggleButtons dibuat selebar modal dengan segmen sama besar.
     *
     * Filament memberi .fi-btn-group `display: grid; grid-flow-col` dan
     * .fi-fo-toggle-buttons tambahan `width: max-content`, jadi bawaannya
     * menempel kiri dan sempit. Pakai style inline, BUKAN kelas Tailwind:
     * proyek ini tanpa build frontend, jadi utility seperti `mx-auto` tidak
     * ikut terkompilasi di CSS bawaan Filament dan diam-diam tidak berefek.
     *
     * Selebar penuh sekaligus menyelesaikan dua hal: rata tengah otomatis,
     * dan target ketuk yang layak di layar HP.
     *
     * @var array<string, string>
     */
    private const SEGMENTED_CONTROL = ['style' => 'width: 100%; grid-auto-columns: 1fr;'];

    /**
     * Push utama lewat Reverb (target ≤2 detik, §6). ->poll('15s') di
     * table() di bawah tetap jadi fallback kalau koneksi WebSocket putus.
     */
    #[On('echo-private:panel.units,.session.started')]
    #[On('echo-private:panel.units,.session.extended')]
    #[On('echo-private:panel.units,.session.ending')]
    #[On('echo-private:panel.units,.session.ended')]
    #[On('echo-private:panel.units,.device-alert.raised')]
    #[On('echo-private:panel.units,.unit.power-state-changed')]
    public function refreshUnits(): void
    {
        //
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Unit::query()
                    ->with(['unitType', 'activeSession.package', 'activeSession.openedBy'])
                    ->withCount(['deviceAlerts as open_alerts_count' => fn (Builder $query) => $query->where('status', DeviceAlertStatus::Open)])
            )
            // Maksimal 3 kolom (bukan 4): kartu jadi lebih lebar sehingga nama
            // pelanggan & tombol aksi punya ruang layak untuk ditekan kasir.
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ])
            ->paginated(false)
            ->poll('15s')
            ->defaultSort('code')
            ->columns([
                Stack::make([
                    // Badge alert ikut di baris judul (bukan baris sendiri di
                    // tengah kartu) supaya tinggi kartu tetap seragam antara
                    // unit yang punya alert dan yang tidak.
                    //
                    // grow(false) pada kedua badge WAJIB: bawaan Split memberi
                    // setiap kolom porsi lebar yang sama, jadi badge ikut
                    // melar dan berhenti di titik yang berbeda-beda tergantung
                    // ada tidaknya badge alert. Dengan grow(false) hanya kode
                    // unit yang memuai, dan badge status selalu mentok kanan.
                    Split::make([
                        TextColumn::make('code')
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Large),
                        TextColumn::make('open_alerts_count')
                            ->badge()
                            ->grow(false)
                            ->color('danger')
                            ->icon(Heroicon::OutlinedExclamationTriangle)
                            ->tooltip(fn (?Unit $record) => "{$record?->open_alerts_count} alert belum ditangani")
                            ->visible(fn (?Unit $record) => $record?->open_alerts_count > 0),
                        TextColumn::make('power_state')
                            ->badge()
                            ->grow(false)
                            ->tooltip(fn (?Unit $record) => 'Status daya TV: '.$record?->power_state?->getLabel()),
                    ]),
                    // Susunannya SELALU tiga baris, isinya yang berubah — tidak
                    // ada kolom yang disembunyikan. Kolom tersembunyi membuat
                    // tinggi kartu berbeda-beda antara unit terisi dan kosong,
                    // sehingga barisnya tidak sejajar dan mata harus mencari
                    // ulang di tiap kartu. Kiri = identitas, kanan = keadaan.
                    Split::make([
                        TextColumn::make('unitType.name')
                            ->color('gray')
                            ->size(TextSize::Small),
                        TextColumn::make('session_type')
                            ->label('Tipe')
                            ->alignEnd()
                            ->size(TextSize::Small)
                            ->color('gray')
                            ->state(function (?Unit $record): string {
                                $session = $record?->activeSession;

                                if (! $session) {
                                    return 'Unit kosong';
                                }

                                return $session->type === SessionType::Package
                                    ? $session->type->getLabel().' — '.$session->package?->name
                                    : $session->type->getLabel();
                            }),
                    ]),
                    Split::make([
                        TextColumn::make('session_customer')
                            ->label('Pelanggan')
                            ->icon(Heroicon::OutlinedUser)
                            ->weight(FontWeight::Medium)
                            ->color(fn (?Unit $record) => $record?->activeSession ? null : 'gray')
                            ->state(fn (?Unit $record) => $record?->activeSession
                                ? ($record->activeSession->customer_name ?: 'Tanpa nama')
                                : 'Siap dipakai'),
                        // Timer adalah angka yang paling sering dilihat kasir —
                        // dibuat paling menonjol di dalam kartu. Satu kolom untuk
                        // hitung mundur (paket) maupun hitung maju (open play):
                        // dua kolom yang saling menyembunyikan adalah persis yang
                        // membuat barisnya tidak pernah sejajar.
                        TextColumn::make('session_timer')
                            ->label('Waktu')
                            ->icon(fn (?Unit $record) => $record?->activeSession ? Heroicon::OutlinedClock : null)
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold)
                            ->alignEnd()
                            ->color(fn (?Unit $record) => match (true) {
                                $record?->activeSession === null => 'gray',
                                $record->activeSession->ends_at !== null => 'warning',
                                default => 'success',
                            })
                            ->state(fn (?Unit $record) => $record?->activeSession
                                ? ($record->activeSession->ends_at ?? $record->activeSession->started_at)->format('H:i')
                                : '—')
                            ->extraAttributes(function (?Unit $record): array {
                                $session = $record?->activeSession;

                                if (! $session) {
                                    return [];
                                }

                                $timer = $session->ends_at
                                    ? "countdown('".$session->ends_at->toIso8601String()."')"
                                    : "countup('".$session->started_at->toIso8601String()."')";

                                return ['x-data' => $timer, 'x-text' => 'display'];
                            }),
                    ]),
                ])->space(2),
            ])
            ->recordActions([
                $this->startSessionAction(),
                $this->extendSessionAction(),
                $this->stopSessionAction(),
                $this->togglePowerAction(),
            ], position: RecordActionsPosition::AfterColumns)
            ->toolbarActions([]);
    }

    protected function startSessionAction(): Action
    {
        return Action::make('start')
            ->button()
            ->size(Size::Small)
            ->label('Mulai')
            ->color('success')
            ->icon('heroicon-o-play')
            ->visible(fn (?Unit $record) => $record?->activeSession === null)
            ->modalWidth(Width::Medium)
            ->modalAlignment(Alignment::Center)
            ->modalFooterActionsAlignment(Alignment::Center)
            ->modalIcon('heroicon-o-play-circle')
            ->modalIconColor('success')
            ->modalHeading(fn (?Unit $record) => 'Mulai Sesi — '.$record?->code)
            ->modalDescription(fn (?Unit $record) => $record?->unitType?->name
                ? $record->unitType->name.' · '.Rupiah::format($record->unitType->hourly_rate).'/jam'
                : null)
            ->modalSubmitActionLabel('Mulai sesi')
            ->schema(function (?Unit $record) {
                return [
                    ToggleButtons::make('type')
                        ->hiddenLabel()
                        ->options(['open' => 'Open Play', 'package' => 'Paket'])
                        ->default('open')
                        ->inline()
                        ->grouped()
                        ->extraAttributes(self::SEGMENTED_CONTROL)
                        ->live()
                        ->required(),
                    TextInput::make('customer_name')
                        ->hiddenLabel()
                        ->placeholder('Nama pelanggan (opsional)'),
                    // native(false) WAJIB: dropdown bawaan browser di mobile
                    // muncul sebagai panel lepas di pojok layar, di luar modal.
                    Select::make('package_id')
                        ->hiddenLabel()
                        ->placeholder('Pilih paket')
                        ->options(fn () => Package::query()->where('unit_type_id', $record?->unit_type_id)->where('is_active', true)->pluck('name', 'id'))
                        ->required(fn (Get $get) => $get('type') === 'package')
                        ->visible(fn (Get $get) => $get('type') === 'package'),
                    TextEntry::make('prabayar_catatan')
                        ->hiddenLabel()
                        ->alignCenter()
                        ->state('Paket dibayar di muka.')
                        ->size(TextSize::Small)
                        ->color('gray')
                        ->visible(fn (Get $get) => $get('type') === 'package'),
                    ToggleButtons::make('payment_method')
                        ->hiddenLabel()
                        ->options(PaymentMethod::class)
                        ->inline()
                        ->grouped()
                        ->extraAttributes(self::SEGMENTED_CONTROL)
                        ->required(fn (Get $get) => $get('type') === 'package')
                        ->visible(fn (Get $get) => $get('type') === 'package'),
                ];
            })
            ->action(function (array $data, Unit $record): void {
                $package = isset($data['package_id']) ? Package::find($data['package_id']) : null;

                app(StartSessionAction::class)->handle(
                    $record,
                    Auth::user(),
                    SessionType::from($data['type']),
                    package: $package,
                    customerName: $data['customer_name'] ?: null,
                    paymentMethod: self::resolvePaymentMethod($data['payment_method'] ?? null),
                );

                Notification::make()->title('Sesi dimulai')->success()->send();
            });
    }

    protected function extendSessionAction(): Action
    {
        return Action::make('extend')
            ->button()
            ->size(Size::Small)
            ->label('Perpanjang')
            ->color('warning')
            ->icon('heroicon-o-clock')
            ->visible(fn (?Unit $record) => $record?->activeSession?->type === SessionType::Package)
            ->modalWidth(Width::Medium)
            ->modalAlignment(Alignment::Center)
            ->modalFooterActionsAlignment(Alignment::Center)
            ->modalIcon('heroicon-o-clock')
            ->modalIconColor('warning')
            ->modalHeading(fn (?Unit $record) => 'Perpanjang — '.$record?->code)
            ->modalDescription(fn (?Unit $record) => self::sessionSummary($record?->activeSession))
            ->modalSubmitActionLabel('Perpanjang sesi')
            ->schema([
                // Satuannya dipasang sebagai prefix/suffix, bukan label: label
                // rata kiri merusak susunan modal yang rata tengah, sedangkan
                // "menit" dan "Rp" TIDAK boleh hilang — ini kolom uang & waktu.
                TextInput::make('added_minutes')
                    ->hiddenLabel()
                    ->placeholder('Tambah durasi')
                    ->suffix('menit')
                    ->numeric()
                    ->minValue(1)
                    ->required(),
                TextInput::make('amount')
                    ->hiddenLabel()
                    ->placeholder('Biaya tambahan')
                    ->prefix('Rp')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
            ])
            ->action(function (array $data, Unit $record): void {
                app(ExtendSessionAction::class)->handle(
                    $record->activeSession,
                    addedMinutes: (int) $data['added_minutes'],
                    amount: (int) $data['amount'],
                    user: Auth::user(),
                );

                Notification::make()->title('Sesi diperpanjang')->success()->send();
            });
    }

    /**
     * Dua bentuk, satu tombol:
     *
     * - Sesi PAKET sudah lunas di muka, jadi tidak ada yang perlu diisi —
     *   cukup konfirmasi ringkas seperti modal "Nyalakan TV".
     * - Sesi OPEN PLAY perlu uang berpindah tangan sekarang, jadi kasir
     *   memilih metode bayar dulu, lalu MENEGASKAN lewat modal kedua
     *   (stopConfirm) bahwa uangnya benar-benar sudah diterima. Sekali klik
     *   untuk sesuatu yang tidak bisa dibatalkan terlalu mudah salah tekan.
     */
    protected function stopSessionAction(): Action
    {
        return Action::make('stop')
            ->button()
            ->size(Size::Small)
            ->label('Stop & Bayar')
            ->color('danger')
            ->icon('heroicon-o-stop')
            ->visible(fn (?Unit $record) => $record?->activeSession !== null)
            // Modal sempit & rata tengah: isinya sedikit, dan lebar default
            // membuatnya tampak kosong melompong. Heading menyebut unitnya
            // supaya kasir tidak salah menutup unit yang sedang ramai.
            ->modalWidth(Width::Medium)
            ->modalAlignment(Alignment::Center)
            ->modalFooterActionsAlignment(Alignment::Center)
            ->requiresConfirmation(fn (?Unit $record) => self::isPrepaid($record?->activeSession))
            ->modalIcon(fn (?Unit $record) => self::isPrepaid($record?->activeSession)
                ? 'heroicon-o-exclamation-triangle'
                : 'heroicon-o-banknotes')
            ->modalIconColor('danger')
            ->modalHeading(fn (?Unit $record) => (self::isPrepaid($record?->activeSession) ? 'Tutup Sesi — ' : 'Stop & Bayar — ').$record?->code)
            ->modalDescription(function (?Unit $record): ?string {
                $session = $record?->activeSession;
                $summary = self::sessionSummary($session);

                if (! self::isPrepaid($session)) {
                    return $summary;
                }

                $paid = Rupiah::format(self::estimateTotal($session));
                $method = $session->payment_method?->getLabel();

                return $summary."\nSudah lunas {$paid}".($method ? " ({$method})" : '').'. Tutup sekarang?';
            })
            ->modalSubmitActionLabel(fn (?Unit $record) => self::isPrepaid($record?->activeSession) ? 'Konfirmasi' : 'Lanjut')
            ->schema(function (?Unit $record) {
                $session = $record?->activeSession;

                if (self::isPrepaid($session)) {
                    return [];
                }

                return [
                    // Angka ini yang dibacakan kasir ke pelanggan — dibuat
                    // elemen paling menonjol di modal, bukan teks biasa.
                    TextEntry::make('estimasi_total')
                        ->hiddenLabel()
                        ->alignCenter()
                        ->state($session ? Rupiah::format(self::estimateTotal($session)) : '-')
                        ->size(TextSize::Large)
                        ->weight(FontWeight::Bold)
                        ->color('success'),
                    // Keterangan sengaja jadi entry tersendiri, bukan
                    // ->helperText(): helper text selalu rata kiri dan merusak
                    // susunan modal yang seluruhnya rata tengah.
                    TextEntry::make('estimasi_catatan')
                        ->hiddenLabel()
                        ->alignCenter()
                        ->state('Dihitung dari lama pakai sampai detik ini.')
                        ->size(TextSize::Small)
                        ->color('gray'),
                    // Tombol segmen, bukan dropdown: satu ketukan (bukan dua),
                    // pilihannya terbaca sekaligus, dan lebarnya penuh sehingga
                    // ikut rata tengah tanpa perlu CSS tambahan.
                    ToggleButtons::make('payment_method')
                        ->hiddenLabel()
                        ->options(PaymentMethod::class)
                        ->inline()
                        ->grouped()
                        ->extraAttributes(self::SEGMENTED_CONTROL)
                        ->required(),
                ];
            })
            ->action(function (array $data, Unit $record): void {
                $session = $record->activeSession;

                if (self::isPrepaid($session)) {
                    self::completeSession($session, null);

                    return;
                }

                // Langkah kedua: modal konfirmasi. Sesi & metode bayar dibawa
                // sebagai argumen karena aksi kedua tidak terikat ke record.
                $this->replaceMountedAction('stopConfirm', [
                    'session' => $session->getKey(),
                    'paymentMethod' => self::resolvePaymentMethod($data['payment_method'] ?? null)?->value,
                ]);
            });
    }

    /**
     * Langkah kedua dari "Stop & Bayar" untuk sesi Open Play: penegasan bahwa
     * uangnya sudah benar-benar diterima. Sengaja aksi terpisah (bukan record
     * action) supaya tidak ikut muncul sebagai tombol di kartu unit.
     */
    public function stopConfirmAction(): Action
    {
        return Action::make('stopConfirm')
            ->requiresConfirmation()
            ->modalWidth(Width::Medium)
            ->modalIcon('heroicon-o-banknotes')
            ->modalIconColor('danger')
            ->modalHeading('Konfirmasi pembayaran')
            ->modalDescription(function (array $arguments): string {
                $session = RentalSession::find($arguments['session'] ?? null);

                if (! $session) {
                    return 'Sesi sudah tidak aktif.';
                }

                $method = self::resolvePaymentMethod($arguments['paymentMethod'] ?? null)?->getLabel() ?? 'metode terpilih';
                $who = $session->customer_name ?: 'pelanggan';

                return "Terima secara {$method} dari {$who} — {$session->unit->code}?";
            })
            // Nominalnya diulang di sini, bukan cuma di kalimat: ini angka
            // terakhir yang dilihat kasir sebelum uang dianggap masuk.
            //
            // Schema ini juga TIDAK boleh kosong. replaceMountedAction()
            // membuang cache schema tapi meninggalkan $discoveredSchemaNames,
            // jadi aksi pengganti tanpa schema membuat request berikutnya
            // mencari mountedActionSchema0 yang sudah tidak ada.
            ->schema(fn (array $arguments) => [
                TextEntry::make('total')
                    ->hiddenLabel()
                    ->alignCenter()
                    ->state(fn () => ($session = RentalSession::find($arguments['session'] ?? null))
                        ? Rupiah::format(self::estimateTotal($session))
                        : '-')
                    ->size(TextSize::Large)
                    ->weight(FontWeight::Bold)
                    ->color('success'),
            ])
            ->modalSubmitActionLabel('Ya, sudah lunas')
            ->action(function (array $arguments): void {
                $session = RentalSession::find($arguments['session'] ?? null);

                if (! $session) {
                    Notification::make()->title('Sesi sudah tidak aktif.')->danger()->send();

                    return;
                }

                self::completeSession($session, self::resolvePaymentMethod($arguments['paymentMethod'] ?? null));
            });
    }

    /**
     * Sesi paket dibayar di muka saat dimulai, jadi saat ditutup tidak ada
     * uang yang berpindah tangan lagi.
     */
    private static function isPrepaid(?RentalSession $session): bool
    {
        return $session?->type === SessionType::Package;
    }

    private static function completeSession(RentalSession $session, ?PaymentMethod $paymentMethod): void
    {
        $completed = app(CompleteSessionAction::class)->handle($session, $paymentMethod);

        Notification::make()
            ->title('Sesi selesai — Total: '.Rupiah::format($completed->total_amount))
            ->success()
            ->send();
    }

    protected function togglePowerAction(): Action
    {
        // Ikon saja, bukan tombol berlabel: saat sesi paket berjalan kartu ini
        // memuat TIGA aksi (Perpanjang + Stop & Bayar + daya). Dengan label
        // penuh, tombol ketiga meluber keluar kartu dan menimpa kartu
        // sebelahnya. Daya adalah aksi bantu, jadi ia yang diringkas — label
        // tetap terbaca lewat tooltip.
        return Action::make('togglePower')
            ->iconButton()
            ->size(Size::Small)
            ->tooltip(fn (?Unit $record) => $record?->power_state === PowerState::On ? 'Matikan TV' : 'Nyalakan TV')
            ->label(fn (?Unit $record) => $record?->power_state === PowerState::On ? 'Matikan TV' : 'Nyalakan TV')
            ->color('gray')
            ->icon('heroicon-o-power')
            ->requiresConfirmation()
            ->modalWidth(Width::Medium)
            // Teks bawaan ("Apakah Anda yakin ingin melakukan ini?") tidak
            // memberi tahu apa pun. Yang perlu diketahui kasir sebelum menekan:
            // unit mana, lewat driver apa, dan — ini yang penting — bahwa
            // mematikan TV TIDAK menutup sesi atau menagih pelanggan.
            ->modalIcon(fn (?Unit $record) => $record?->power_state === PowerState::On
                ? 'heroicon-o-power'
                : 'heroicon-o-bolt')
            ->modalIconColor(fn (?Unit $record) => $record?->power_state === PowerState::On ? 'danger' : 'success')
            ->modalHeading(fn (?Unit $record) => ($record?->power_state === PowerState::On ? 'Matikan TV — ' : 'Nyalakan TV — ').$record?->code)
            ->modalDescription(function (?Unit $record): string {
                $driver = $record?->control_driver;

                if ($driver === ControlDriver::Manual) {
                    return 'Unit ini diatur Manual — sistem hanya mencatat statusnya, TV tetap harus ditekan sendiri.';
                }

                $via = $driver?->getLabel() ?? 'perangkat';

                if ($record?->power_state !== PowerState::On) {
                    return "Perintah nyala dikirim lewat {$via}. Status bisa perlu beberapa detik untuk berubah.";
                }

                $running = $record->activeSession
                    ? ' Sesi '.($record->activeSession->customer_name ?: 'tanpa nama').' masih berjalan — mematikan TV tidak menutup sesi dan tidak menagih.'
                    : '';

                return "Perintah mati dikirim lewat {$via}.".$running;
            })
            ->modalSubmitActionLabel(fn (?Unit $record) => $record?->power_state === PowerState::On ? 'Matikan' : 'Nyalakan')
            ->action(function (Unit $record): void {
                $turnOn = $record->power_state !== PowerState::On;
                $devices = app(DeviceManager::class);

                $result = $turnOn
                    ? $devices->attempt($record, fn ($driver) => $driver->powerOn($record))
                    : $devices->powerOff($record);

                Notification::make()
                    ->title($result?->successful ? 'Perintah terkirim' : 'Perintah gagal dikirim, cek log.')
                    ->color($result?->successful ? 'success' : 'danger')
                    ->send();
            });
    }

    /**
     * Estimasi yang ditampilkan di modal Stop & Bayar. Sengaja memakai sumber
     * yang SAMA dengan penagihan sungguhan (SessionTotal) supaya angka di layar
     * tidak pernah menyimpang dari yang benar-benar ditagih.
     */
    /**
     * Baris konteks di bawah judul modal: siapa pelanggannya dan sudah
     * berjalan/berakhir kapan. Kasir sering memegang beberapa unit sekaligus,
     * jadi konfirmasi "ini unit yang benar" harus terbaca tanpa menutup modal.
     */
    private static function sessionSummary(?RentalSession $session): ?string
    {
        if (! $session) {
            return null;
        }

        $tz = config('app.display_timezone', config('app.timezone'));
        $who = $session->customer_name ?: 'Tanpa nama';
        $type = $session->type->getLabel();

        $when = $session->ends_at
            ? 'berakhir '.$session->ends_at->setTimezone($tz)->format('H:i')
            : 'mulai '.$session->started_at->setTimezone($tz)->format('H:i');

        return "{$who} · {$type} · {$when}";
    }

    public static function estimateTotal(RentalSession $session): int
    {
        return SessionTotal::for($session, now());
    }

    /**
     * Filament's Select::options(PaymentMethod::class) already casts submitted
     * state to a PaymentMethod instance, but this stays defensive in case a
     * raw scalar ever comes through instead.
     */
    private static function resolvePaymentMethod(mixed $value): ?PaymentMethod
    {
        return match (true) {
            $value instanceof PaymentMethod => $value,
            $value === null => null,
            default => PaymentMethod::from($value),
        };
    }
}
