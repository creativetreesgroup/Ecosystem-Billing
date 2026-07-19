<?php

namespace App\Filament\Pages;

use App\Domain\Billing\Rupiah;
use App\Domain\Billing\SalesSummary;
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
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
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

            Section::make('Rincian harian')
                ->schema([EmbeddedTable::make()]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('start_date')->label('Dari tanggal')->native(false)
                    ->live()->afterStateUpdated(fn () => $this->broadcastRange()),
                DatePicker::make('end_date')->label('Sampai tanggal')->native(false)
                    ->live()->afterStateUpdated(fn () => $this->broadcastRange()),
            ])
            ->statePath('data')
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): SupportCollection => collect($this->summary()->dailyBreakdown()))
            ->columns([
                TextColumn::make('date')->label('Tanggal')->date('d M Y'),
                TextColumn::make('sessions')->label('Jumlah sesi'),
                TextColumn::make('revenue')->label('Pendapatan')->formatStateUsing(fn (int $state) => Rupiah::format($state)),
            ])
            ->paginated(false)
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
