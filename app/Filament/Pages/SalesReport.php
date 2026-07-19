<?php

namespace App\Filament\Pages;

use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\Rupiah;
use App\Domain\Sessions\SessionStatus;
use App\Models\RentalSession;
use App\Models\UserRole;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
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
 */
class SalesReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Laporan';

    protected static ?string $title = 'Laporan Penjualan';

    protected string $view = 'filament.pages.sales-report';

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

    protected function range(): array
    {
        $start = Carbon::parse($this->data['start_date'] ?? now()->startOfMonth())->startOfDay();
        $end = Carbon::parse($this->data['end_date'] ?? now())->endOfDay();

        return $end->lt($start) ? [$start, $start->copy()->endOfDay()] : [$start, $end];
    }

    /**
     * Satu query dipakai ulang untuk semua angka rekap — dataset satu outlet
     * kecil, jauh lebih sederhana daripada beberapa query agregat SQL terpisah.
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
     * @return array<int, array{label: string, count: int, revenue: string}>
     */
    public function getRevenueByPaymentMethod(): array
    {
        return $this->completedSessions()
            ->groupBy(fn (RentalSession $session) => $session->payment_method?->value ?? 'unknown')
            ->map(fn (Collection $group, string $method) => [
                'label' => PaymentMethod::tryFrom($method)?->name ?? 'Tidak diketahui',
                'count' => $group->count(),
                'revenue' => Rupiah::format((int) $group->sum('total_amount')),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{label: string, count: int, revenue: string}>
     */
    public function getRevenueByUnitType(): array
    {
        return $this->completedSessions()
            ->groupBy(fn (RentalSession $session) => $session->unit->unitType->name)
            ->map(fn (Collection $group, string $unitType) => [
                'label' => $unitType,
                'count' => $group->count(),
                'revenue' => Rupiah::format((int) $group->sum('total_amount')),
            ])
            ->values()
            ->all();
    }

    public function getBusiestHour(): ?string
    {
        $byHour = $this->completedSessions()->groupBy(fn (RentalSession $session) => $session->started_at->format('H'));

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
                ->groupBy(fn (RentalSession $session) => $session->ended_at->toDateString())
                ->map(fn (Collection $group, string $date) => [
                    'date' => $date,
                    'sessions' => $group->count(),
                    'revenue' => (int) $group->sum('total_amount'),
                ])
                ->sortKeysDesc()
                ->values())
            ->columns([
                TextColumn::make('date')->label('Tanggal')->date('d M Y'),
                TextColumn::make('sessions')->label('Jumlah Sesi'),
                TextColumn::make('revenue')->label('Pendapatan')->formatStateUsing(fn (int $state) => Rupiah::format($state)),
            ])
            ->paginated(false);
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
                    $session->ended_at->format('Y-m-d H:i'),
                    $session->unit->code,
                    $session->unit->unitType->name,
                    $session->customer_name ?? '-',
                    $session->type->value,
                    $session->payment_method?->value ?? '-',
                    $session->total_amount,
                ]);
            }

            fclose($handle);
        }, "laporan-{$start->toDateString()}-{$end->toDateString()}.csv");
    }
}
