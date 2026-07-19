<?php

namespace App\Filament\Widgets;

use App\Domain\Billing\Rupiah;
use App\Domain\Billing\SalesSummary;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

/**
 * Kartu ringkasan di halaman Laporan — memakai StatsOverviewWidget bawaan
 * Filament, bukan grid TextEntry buatan sendiri, supaya ikut gaya kartu
 * statistik standar (ikon, deskripsi, sparkline) tanpa CSS kustom.
 *
 * Tidak muncul di Dasbor karena App\Filament\Pages\Dashboard menyebut
 * widgetnya secara eksplisit — BUKAN lewat canView(), yang justru akan
 * membuat widget ini abort(403) saat disematkan di halaman Laporan.
 */
class SalesStatsWidget extends StatsOverviewWidget
{
    public ?string $startDate = null;

    public ?string $endDate = null;

    protected int|string|array $columnSpan = 'full';

    // Polling bawaan widget 5 detik terlalu boros untuk laporan yang
    // meng-query seluruh rentang; 30 detik cukup sebagai cadangan, sementara
    // push Reverb di bawah membuat angka tetap terasa langsung berubah.
    protected ?string $pollingInterval = '30s';

    #[On('echo-private:panel.units,.session.ended')]
    public function refreshOnSessionEnded(): void {}

    #[On('sales-range-updated')]
    public function applyRange(?string $startDate, ?string $endDate): void
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    protected function getStats(): array
    {
        $summary = new SalesSummary($this->startDate, $this->endDate);
        $series = $summary->dailySeries();

        return [
            Stat::make('Jumlah sesi', (string) $summary->totalSessions())
                ->description('Sesi selesai pada rentang ini')
                ->descriptionIcon(Heroicon::OutlinedPlayCircle)
                ->chart($this->sparkline($series['sessions']))
                ->color('info'),

            Stat::make('Total pendapatan', Rupiah::format($summary->totalRevenue()))
                ->description('Rata-rata '.Rupiah::format($summary->averageRevenue()).' per sesi')
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->chart($this->sparkline($series['revenue']))
                ->color('success'),

            Stat::make('Jam tersibuk', $summary->busiestHour() ?? '—')
                ->description($summary->busiestHour() ? 'Jam mulai sesi terbanyak' : 'Belum ada sesi')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color('warning'),
        ];
    }

    /**
     * Sparkline butuh minimal dua titik supaya tergambar sebagai garis, dan
     * jadi tidak terbaca kalau titiknya ratusan — diambil 14 hari terakhir.
     *
     * @param  array<int, int>  $values
     * @return array<int, int>|null
     */
    private function sparkline(array $values): ?array
    {
        $tail = array_slice($values, -14);

        return count($tail) >= 2 ? $tail : null;
    }
}
