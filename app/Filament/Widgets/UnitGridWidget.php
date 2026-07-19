<?php

namespace App\Filament\Widgets;

use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\Rupiah;
use App\Domain\Billing\SessionTotal;
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
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\TextSize;
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
                    Split::make([
                        TextColumn::make('code')
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Large),
                        TextColumn::make('open_alerts_count')
                            ->badge()
                            ->color('danger')
                            ->icon(Heroicon::OutlinedExclamationTriangle)
                            ->tooltip(fn (?Unit $record) => "{$record?->open_alerts_count} alert belum ditangani")
                            ->visible(fn (?Unit $record) => $record?->open_alerts_count > 0),
                        TextColumn::make('power_state')
                            ->badge(),
                    ]),
                    TextColumn::make('unitType.name')
                        ->color('gray')
                        ->size(TextSize::Small),
                    TextColumn::make('activeSession.customer_name')
                        ->label('Pelanggan')
                        ->icon(Heroicon::OutlinedUser)
                        ->weight(FontWeight::Medium)
                        ->placeholder('Tanpa nama')
                        ->visible(fn (?Unit $record) => $record?->activeSession !== null),
                    TextColumn::make('activeSession.type')
                        ->label('Tipe')
                        ->color('gray')
                        ->size(TextSize::Small)
                        ->formatStateUsing(function (?Unit $record): ?string {
                            $session = $record?->activeSession;

                            return $session?->type === SessionType::Package
                                ? $session->type->getLabel().' — '.$session->package?->name
                                : $session?->type?->getLabel();
                        })
                        ->visible(fn (?Unit $record) => $record?->activeSession !== null),
                    // Timer adalah angka yang paling sering dilihat kasir —
                    // dibuat paling menonjol di dalam kartu.
                    TextColumn::make('activeSession.ends_at')
                        ->label('Sisa waktu')
                        ->icon(Heroicon::OutlinedClock)
                        ->size(TextSize::Large)
                        ->weight(FontWeight::Bold)
                        ->color('warning')
                        ->formatStateUsing(fn (?Unit $record) => $record?->activeSession?->ends_at?->format('H:i'))
                        ->extraAttributes(fn (?Unit $record) => $record?->activeSession?->ends_at
                            ? [
                                'x-data' => "countdown('".$record->activeSession->ends_at->toIso8601String()."')",
                                'x-text' => 'display',
                            ]
                            : [])
                        ->visible(fn (?Unit $record) => $record?->activeSession?->ends_at !== null),
                    TextColumn::make('activeSession.started_at')
                        ->label('Berjalan')
                        ->icon(Heroicon::OutlinedClock)
                        ->size(TextSize::Large)
                        ->weight(FontWeight::Bold)
                        ->color('success')
                        ->formatStateUsing(fn (?Unit $record) => $record?->activeSession?->started_at?->format('H:i'))
                        ->extraAttributes(fn (?Unit $record) => $record?->activeSession
                            ? [
                                'x-data' => "countup('".$record->activeSession->started_at->toIso8601String()."')",
                                'x-text' => 'display',
                            ]
                            : [])
                        ->visible(fn (?Unit $record) => $record?->activeSession !== null && $record->activeSession->ends_at === null),
                    TextColumn::make('empty_state')
                        ->label('')
                        ->state('Unit kosong')
                        ->color('gray')
                        ->size(TextSize::Small)
                        ->visible(fn (?Unit $record) => $record?->activeSession === null),
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
            ->modalHeading(fn (?Unit $record) => 'Mulai Sesi — '.$record?->code)
            ->schema(function (?Unit $record) {
                return [
                    Radio::make('type')
                        ->label('Tipe sesi')
                        ->options(['open' => 'Open Play', 'package' => 'Paket'])
                        ->default('open')
                        ->live()
                        ->required(),
                    TextInput::make('customer_name')
                        ->label('Nama pelanggan (opsional)'),
                    Select::make('package_id')
                        ->label('Paket')
                        ->options(fn () => Package::query()->where('unit_type_id', $record?->unit_type_id)->where('is_active', true)->pluck('name', 'id'))
                        ->required(fn (Get $get) => $get('type') === 'package')
                        ->visible(fn (Get $get) => $get('type') === 'package'),
                    Select::make('payment_method')
                        ->label('Metode pembayaran (dibayar di muka)')
                        ->options(PaymentMethod::class)
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
            ->schema([
                TextInput::make('added_minutes')
                    ->label('Tambah durasi (menit)')
                    ->numeric()
                    ->minValue(1)
                    ->required(),
                TextInput::make('amount')
                    ->label('Biaya tambahan (Rp)')
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

    protected function stopSessionAction(): Action
    {
        return Action::make('stop')
            ->button()
            ->size(Size::Small)
            ->label('Stop & Bayar')
            ->color('danger')
            ->icon('heroicon-o-stop')
            ->modalHeading('Stop & Bayar')
            ->visible(fn (?Unit $record) => $record?->activeSession !== null)
            ->schema(function (?Unit $record) {
                $session = $record?->activeSession;

                return [
                    Placeholder::make('estimasi_total')
                        ->label('Total tagihan')
                        ->content($session ? Rupiah::format(self::estimateTotal($session)) : '-'),
                    Select::make('payment_method')
                        ->label('Metode pembayaran')
                        ->options(PaymentMethod::class)
                        ->required(fn () => $session?->type === SessionType::Open)
                        ->visible(fn () => $session?->type === SessionType::Open),
                ];
            })
            ->action(function (array $data, Unit $record): void {
                $completed = app(CompleteSessionAction::class)->handle(
                    $record->activeSession,
                    self::resolvePaymentMethod($data['payment_method'] ?? null),
                );

                Notification::make()
                    ->title('Sesi selesai — Total: '.Rupiah::format($completed->total_amount))
                    ->success()
                    ->send();
            });
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
