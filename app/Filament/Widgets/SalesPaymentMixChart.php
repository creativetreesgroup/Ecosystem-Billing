<?php

namespace App\Filament\Widgets;

use App\Domain\Billing\SalesSummary;
use Filament\Support\RawJs;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;
use Livewire\Attributes\On;

/**
 * Komposisi pendapatan harian per metode bayar (batang bertumpuk).
 *
 * Melengkapi SalesRevenueChart yang hanya menunjukkan totalnya: grafik ini
 * menjawab "hari itu uangnya masuk lewat mana" — tunai yang harus ada di laci
 * versus QRIS/transfer yang masuk rekening. Itu yang dipakai saat menutup kas.
 *
 * Tidak muncul di Dasbor karena Dashboard menyebut widgetnya eksplisit, dan
 * angkanya diambil dari SalesSummary yang sama dengan halaman Laporan.
 */
class SalesPaymentMixChart extends ApexChartWidget
{
    protected static ?string $chartId = 'salesPaymentMixChart';

    protected static ?string $heading = 'Komposisi pembayaran harian';

    public ?string $startDate = null;

    public ?string $endDate = null;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '30s';

    #[On('echo-private:panel.units,.session.ended')]
    public function refreshOnSessionEnded(): void
    {
        $this->updateOptions();
    }

    #[On('sales-range-updated')]
    public function applyRange(?string $startDate, ?string $endDate): void
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;

        $this->updateOptions();
    }

    protected function getOptions(): array
    {
        $series = (new SalesSummary($this->startDate, $this->endDate))->dailyPaymentSeries();

        return [
            'chart' => [
                'type' => 'bar',
                'height' => 300,
                'stacked' => true,
                'toolbar' => ['show' => false],
            ],
            'series' => $series['series'],
            'xaxis' => [
                'categories' => $series['labels'],
                'labels' => ['rotate' => -45, 'rotateAlways' => count($series['labels']) > 12],
            ],
            // Urutan warna mengikuti urutan PaymentMethod::cases():
            // Tunai (hijau), QRIS (biru), Transfer (kuning) — sengaja sama
            // dengan warna badge metode bayar di tabel Riwayat Sesi.
            'colors' => ['#22c55e', '#3b82f6', '#f59e0b'],
            'plotOptions' => ['bar' => ['borderRadius' => 3, 'columnWidth' => '60%']],
            'dataLabels' => ['enabled' => false],
            'legend' => ['position' => 'top', 'horizontalAlign' => 'left'],
            'grid' => ['borderColor' => '#374151', 'strokeDashArray' => 4],
        ];
    }

    protected function extraJsOptions(): ?RawJs
    {
        return RawJs::make(<<<'JS'
        {
            yaxis: {
                labels: {
                    formatter: function (val) {
                        if (val >= 1000000) return 'Rp' + (val / 1000000).toFixed(1) + 'jt'
                        if (val >= 1000) return 'Rp' + Math.round(val / 1000) + 'rb'
                        return 'Rp' + val
                    }
                }
            },
            tooltip: {
                shared: true,
                intersect: false,
                y: {
                    formatter: function (val) {
                        return 'Rp ' + new Intl.NumberFormat('id-ID').format(val)
                    }
                }
            }
        }
        JS);
    }
}
