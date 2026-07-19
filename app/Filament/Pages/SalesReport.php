<?php

namespace App\Filament\Pages;

use App\Domain\Billing\Rupiah;
use App\Domain\Sessions\SessionStatus;
use App\Models\RentalSession;
use App\Models\UserRole;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * §8: "Laporan (owner-only): rekap harian & bulanan — jumlah sesi, pendapatan
 * per metode bayar & per tipe unit, jam sibuk. Export CSV." Rentang tanggal
 * bebas (bukan toggle harian/bulanan terpisah) supaya satu halaman melayani
 * baik rekap satu hari maupun satu bulan — breakdown per hari di tabel bawah
 * tetap memberi rincian harian walau rentang yang dipilih sebulan penuh.
 *
 * Seluruh tampilan disusun lewat content() memakai komponen schema bawaan
 * Filament (Section/Grid/TextEntry/KeyValueEntry/EmbeddedTable) — tanpa Blade
 * kustom, supaya stylingnya ikut CSS Filament yang sudah ter-compile dan
 * proyek ini tidak butuh build step frontend sama sekali (lihat DECISIONS.md).
 */
class SalesReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Laporan';

    protected static ?string $title = 'Laporan Penjualan';

    public ?array $data = [];

    private ?Collection $sessionsCache = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->role === UserRole::Owner;
    }

    public function mount(): void
    {
        $this->form->fill([
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->toDateString(),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Filter')
                ->schema([EmbeddedSchema::make('form')]),

            Section::make('Ringkasan')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('total_sessions')
                            ->label('Jumlah sesi')
                            ->state(fn (): int => $this->getTotalSessions())
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold),
                        TextEntry::make('total_revenue')
                            ->label('Total pendapatan')
                            ->state(fn (): string => $this->getTotalRevenue())
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold)
                            ->color('success'),
                        TextEntry::make('busiest_hour')
                            ->label('Jam tersibuk')
                            ->state(fn (): ?string => $this->getBusiestHour())
                            ->placeholder('Belum ada sesi')
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold),
                    ]),
                ]),

            Grid::make(2)->schema([
                Section::make('Pendapatan per metode bayar')
                    ->schema([
                        KeyValueEntry::make('revenue_by_payment_method')
                            ->hiddenLabel()
                            ->state(fn (): array => $this->getRevenueByPaymentMethod())
                            ->keyLabel('Metode bayar')
                            ->valueLabel('Pendapatan')
                            ->placeholder('Tidak ada data pada rentang ini.'),
                    ]),
                Section::make('Pendapatan per tipe unit')
                    ->schema([
                        KeyValueEntry::make('revenue_by_unit_type')
                            ->hiddenLabel()
                            ->state(fn (): array => $this->getRevenueByUnitType())
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
                DatePicker::make('start_date')->label('Dari tanggal')->native(false)->live(),
                DatePicker::make('end_date')->label('Sampai tanggal')->native(false)->live(),
            ])
            ->statePath('data')
            ->columns(2);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function range(): array
    {
        // Tanggal yang dipilih owner adalah tanggal DINDING outlet (WIB), tapi
        // kolom ended_at disimpan UTC. Batas hari harus dibentuk di zona outlet
        // lalu dikonversi ke UTC — kalau tidak, "19 Jul" sebenarnya mencakup
        // 19 Jul 07:00 s/d 20 Jul 07:00 WIB dan pendapatan bocor antar hari.
        $tz = self::displayTimezone();

        $start = Carbon::parse($this->data['start_date'] ?? now($tz)->startOfMonth(), $tz)->startOfDay();
        $end = Carbon::parse($this->data['end_date'] ?? now($tz), $tz)->endOfDay();

        if ($end->lt($start)) {
            $end = $start->copy()->endOfDay();
        }

        return [$start->utc(), $end->utc()];
    }

    private static function displayTimezone(): string
    {
        return config('app.display_timezone', config('app.timezone'));
    }

    /**
     * Satu query dipakai ulang untuk semua angka rekap — dataset satu outlet
     * kecil, jauh lebih sederhana daripada beberapa query agregat SQL terpisah.
     *
     * @return Collection<int, RentalSession>
     */
    protected function completedSessions(): Collection
    {
        if ($this->sessionsCache) {
            return $this->sessionsCache;
        }

        [$start, $end] = $this->range();

        return $this->sessionsCache = RentalSession::query()
            ->with('unit.unitType')
            ->where('status', SessionStatus::Completed)
            ->whereBetween('ended_at', [$start, $end])
            ->get();
    }

    public function getTotalSessions(): int
    {
        return $this->completedSessions()->count();
    }

    public function getTotalRevenue(): string
    {
        return Rupiah::format((int) $this->completedSessions()->sum('total_amount'));
    }

    /**
     * @return array<string, string>
     */
    public function getRevenueByPaymentMethod(): array
    {
        return $this->summarize(fn (RentalSession $session) => $session->payment_method?->getLabel() ?? 'Tidak diketahui');
    }

    /**
     * @return array<string, string>
     */
    public function getRevenueByUnitType(): array
    {
        return $this->summarize(fn (RentalSession $session) => $session->unit->unitType->name);
    }

    /**
     * Bentuk "Label (n sesi) => Rp…" yang langsung dipakai KeyValueEntry.
     *
     * @return array<string, string>
     */
    private function summarize(callable $groupBy): array
    {
        return $this->completedSessions()
            ->groupBy($groupBy)
            ->mapWithKeys(fn (Collection $group, string $label) => [
                "{$label} ({$group->count()} sesi)" => Rupiah::format((int) $group->sum('total_amount')),
            ])
            ->all();
    }

    public function getBusiestHour(): ?string
    {
        $byHour = $this->completedSessions()->groupBy(fn (RentalSession $session) => $session->started_at->setTimezone(self::displayTimezone())->format('H'));

        if ($byHour->isEmpty()) {
            return null;
        }

        $peak = $byHour->sortByDesc(fn (Collection $group) => $group->count())->keys()->first();

        return "{$peak}:00–".str_pad((string) ((int) $peak + 1), 2, '0', STR_PAD_LEFT).':00';
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): SupportCollection => $this->completedSessions()
                ->groupBy(fn (RentalSession $session) => $session->ended_at->setTimezone(self::displayTimezone())->toDateString())
                ->map(fn (Collection $group, string $date) => [
                    'date' => $date,
                    'sessions' => $group->count(),
                    'revenue' => (int) $group->sum('total_amount'),
                ])
                ->sortKeysDesc()
                ->values())
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
        [$start, $end] = $this->range();

        return Response::streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Tanggal Selesai', 'Unit', 'Tipe Unit', 'Pelanggan', 'Tipe Sesi', 'Metode Bayar', 'Total (Rp)']);

            foreach ($this->completedSessions()->sortBy('ended_at') as $session) {
                fputcsv($handle, [
                    $session->ended_at->setTimezone(self::displayTimezone())->format('Y-m-d H:i'),
                    $session->unit->code,
                    $session->unit->unitType->name,
                    $session->customer_name ?? '-',
                    $session->type->value,
                    $session->payment_method?->value ?? '-',
                    $session->total_amount,
                ]);
            }

            fclose($handle);
        }, 'laporan-'.$start->setTimezone(self::displayTimezone())->toDateString().'-'.$end->setTimezone(self::displayTimezone())->toDateString().'.csv');
    }
}
