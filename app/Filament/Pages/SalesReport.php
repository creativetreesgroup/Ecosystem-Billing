<?php

namespace App\Filament\Pages;

use App\Domain\Billing\Rupiah;
use App\Domain\Billing\SalesSummary;
use App\Filament\Widgets\SalesPaymentMixChart;
use App\Filament\Widgets\SalesRevenueChart;
use App\Filament\Widgets\SalesStatsWidget;
use App\Models\UserRole;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Livewire\Attributes\On;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * §8: "Laporan (owner-only): rekap harian & bulanan — jumlah sesi, pendapatan
 * per metode bayar & per tipe unit, jam sibuk. Export CSV." Rentang tanggal
 * bebas (bukan toggle harian/bulanan terpisah) supaya satu halaman melayani
 * baik rekap satu hari maupun satu bulan.
 *
 * Seluruh angka diambil dari App\Domain\Billing\SalesSummary — halaman ini,
 * kartu statistik, dan grafik memakai sumber yang SAMA supaya tidak ada tiga
 * salinan rumus & batas tanggal yang bisa menyimpang diam-diam.
 */
class SalesReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Laporan';

    protected static ?string $title = 'Laporan Penjualan';

    public ?array $data = [];

    private ?SalesSummary $summaryCache = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->role === UserRole::Owner;
    }

    public function mount(): void
    {
        // WAJIB zona outlet: SalesSummary membaca kedua tanggal ini sebagai jam
        // dinding outlet. Kalau di-generate dengan now() (UTC), antara
        // 00:00-07:00 WIB tanggalnya mundur sehari dan laporan default
        // diam-diam membuang pendapatan malam yang baru saja terjadi.
        $tz = SalesSummary::timezone();

        $this->form->fill([
            'start_date' => now($tz)->startOfMonth()->toDateString(),
            'end_date' => now($tz)->toDateString(),
        ]);
    }

    /**
     * Rincian per metode bayar / tipe unit ada di halaman ini (bukan di
     * widget), jadi halamannya sendiri yang harus ikut menyegarkan diri saat
     * ada sesi baru selesai — kalau tidak, angkanya diam sampai owner
     * memuat ulang. Push utama lewat Reverb (§6); tabel di bawah juga
     * di-poll sebagai cadangan kalau WebSocket putus.
     */
    #[On('echo-private:panel.units,.session.ended')]
    public function refreshReport(): void
    {
        $this->summaryCache = null;
    }

    private function summary(): SalesSummary
    {
        return $this->summaryCache ??= new SalesSummary(
            $this->data['start_date'] ?? null,
            $this->data['end_date'] ?? null,
        );
    }

    /**
     * Widget sudah ter-mount duluan, jadi perubahan filter dikabarkan lewat
     * event — props hanya berlaku saat mount pertama.
     */
    private function broadcastRange(): void
    {
        $this->summaryCache = null;

        $this->dispatch(
            'sales-range-updated',
            startDate: $this->data['start_date'] ?? null,
            endDate: $this->data['end_date'] ?? null,
        );
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Filter')
                ->schema([EmbeddedSchema::make('form')]),

            // Kartu statistik & grafik disematkan sebagai widget Livewire:
            // rentang awal lewat props, perubahan berikutnya lewat event.
            Livewire::make(SalesStatsWidget::class, fn (): array => [
                'startDate' => $this->data['start_date'] ?? null,
                'endDate' => $this->data['end_date'] ?? null,
            ]),

            Livewire::make(SalesRevenueChart::class, fn (): array => [
                'startDate' => $this->data['start_date'] ?? null,
                'endDate' => $this->data['end_date'] ?? null,
            ]),

            Grid::make(2)->schema([
                Section::make('Pendapatan per metode bayar')
                    ->schema([
                        KeyValueEntry::make('revenue_by_payment_method')
                            ->hiddenLabel()
                            ->state(fn (): array => $this->summary()->revenueByPaymentMethod())
                            ->keyLabel('Metode bayar')
                            ->valueLabel('Pendapatan')
                            ->placeholder('Tidak ada data pada rentang ini.'),
                    ]),
                Section::make('Pendapatan per tipe unit')
                    ->schema([
                        KeyValueEntry::make('revenue_by_unit_type')
                            ->hiddenLabel()
                            ->state(fn (): array => $this->summary()->revenueByUnitType())
                            ->keyLabel('Tipe unit')
                            ->valueLabel('Pendapatan')
                            ->placeholder('Tidak ada data pada rentang ini.'),
                    ]),
            ]),

            Livewire::make(SalesPaymentMixChart::class, fn (): array => [
                'startDate' => $this->data['start_date'] ?? null,
                'endDate' => $this->data['end_date'] ?? null,
            ]),

            Section::make('Rincian harian')
                ->description('Tunai harus cocok dengan isi laci; QRIS & transfer dengan mutasi rekening.')
                ->schema([EmbeddedTable::make()]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('start_date')->label('Dari tanggal')
                    ->live()->afterStateUpdated(fn () => $this->broadcastRange()),
                DatePicker::make('end_date')->label('Sampai tanggal')
                    ->live()->afterStateUpdated(fn () => $this->broadcastRange()),
            ])
            ->statePath('data')
            // ['default' => 2], BUKAN columns(2): columns(2) di Filament berarti
            // ['lg' => 2], jadi kedua tanggal baru berdampingan mulai 1024px dan
            // di bawah itu menumpuk. Dua tanggal pendek muat berdampingan di
            // layar mana pun, dan "Dari - Sampai" memang dibaca sebagai satu
            // rentang — bukan dua isian terpisah.
            ->columns(['default' => 2]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): SupportCollection => collect($this->summary()->dailyBreakdown()))
            ->columns([
                TextColumn::make('date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->weight(FontWeight::Medium),
                TextColumn::make('sessions')
                    ->label('Sesi')
                    ->badge()
                    ->color('gray'),
                // Tiga kolom di bawah ini yang membuat rincian ini bisa dipakai
                // MENUTUP KAS: tunai harus cocok dengan isi laci, QRIS &
                // transfer dengan mutasi rekening.
                TextColumn::make('cash')
                    ->label('Tunai')
                    ->formatStateUsing(fn (int $state) => $state > 0 ? Rupiah::format($state) : '—')
                    ->color(fn (int $state) => $state > 0 ? 'success' : 'gray'),
                TextColumn::make('qris')
                    ->label('QRIS')
                    ->formatStateUsing(fn (int $state) => $state > 0 ? Rupiah::format($state) : '—')
                    ->color(fn (int $state) => $state > 0 ? 'info' : 'gray'),
                TextColumn::make('transfer')
                    ->label('Transfer')
                    ->formatStateUsing(fn (int $state) => $state > 0 ? Rupiah::format($state) : '—')
                    ->color(fn (int $state) => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('revenue')
                    ->label('Total')
                    ->formatStateUsing(fn (int $state) => Rupiah::format($state))
                    ->weight(FontWeight::Bold),
                TextColumn::make('average')
                    ->label('Rata-rata/sesi')
                    ->formatStateUsing(fn (int $state) => Rupiah::format($state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('share')
                    ->label('Kontribusi')
                    ->formatStateUsing(fn (float $state) => number_format($state, 1, ',', '.').'%')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->paginated(false)
            ->poll('30s')
            ->emptyStateHeading('Tidak ada sesi selesai pada rentang ini');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label('Export CSV')
                ->icon(Heroicon::ArrowDownTray)
                ->color('gray')
                ->action(fn () => $this->exportCsv()),
        ];
    }

    protected function exportCsv(): StreamedResponse
    {
        $summary = $this->summary();
        $tz = SalesSummary::timezone();
        [$start, $end] = $summary->range();

        return Response::streamDownload(function () use ($summary, $tz): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Tanggal Selesai', 'Unit', 'Tipe Unit', 'Pelanggan', 'Tipe Sesi', 'Metode Bayar', 'Total (Rp)']);

            foreach ($summary->sessions()->sortBy('ended_at') as $session) {
                fputcsv($handle, [
                    $session->ended_at->setTimezone($tz)->format('Y-m-d H:i'),
                    $session->unit->code,
                    $session->unit->unitType->name,
                    $session->customer_name ?? '-',
                    $session->type->value,
                    $session->payment_method?->value ?? '-',
                    $session->total_amount,
                ]);
            }

            fclose($handle);
        }, 'laporan-'.$start->setTimezone($tz)->toDateString().'-'.$end->setTimezone($tz)->toDateString().'.csv');
    }
}
