<?php

namespace App\Filament\Widgets;

use App\Domain\Billing\SalesSummary;
use Filament\Support\RawJs;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;
use Livewire\Attributes\On;

/**
 * Tren pendapatan harian di halaman Laporan (leandrocfe/filament-apex-charts).
 *
 * Tidak muncul di Dasbor karena Dashboard menyebut widgetnya eksplisit, dan
 * angkanya diambil dari SalesSummary yang sama dengan halaman Laporan —
 * bukan query sendiri.
 */
class SalesRevenueChart extends ApexChartWidget
{
    protected static ?string $chartId = 'salesRevenueChart';

    protected static ?string $heading = 'Tren pendapatan harian';

    public ?string $startDate = null;

    public ?string $endDate = null;

    protected int|string|array $columnSpan = 'full';

    #[On('sales-range-updated')]
    public function applyRange(?string $startDate, ?string $endDate): void
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;

        // Widget ini sudah ter-mount, jadi options-nya harus dihitung ulang
        // dan dikirim ke ApexCharts — kalau tidak, grafiknya diam saja saat
        // rentang tanggal diubah.
        $this->updateOptions();
    }

    protected function getOptions(): array
    {
        $series = (new SalesSummary($this->startDate, $this->endDate))->dailySeries();

        return [
            'chart' => [
                'type' => 'area',
                'height' => 300,
                'toolbar' => ['show' => false],
                'zoom' => ['enabled' => false],
            ],
            'series' => [[
                'name' => 'Pendapatan',
                'data' => $series['revenue'],
            ]],
            'xaxis' => [
                'categories' => $series['labels'],
                'labels' => ['rotate' => -45, 'rotateAlways' => count($series['labels']) > 12],
            ],
            'colors' => ['#22c55e'],
            'stroke' => ['curve' => 'smooth', 'width' => 2],
            'dataLabels' => ['enabled' => false],
            'fill' => [
                'type' => 'gradient',
                'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.4, 'opacityTo' => 0.05],
            ],
            'grid' => ['borderColor' => '#374151', 'strokeDashArray' => 4],
        ];
    }

    /**
     * Formatter harus JavaScript sungguhan, jadi lewat extraJsOptions() —
     * nilai string biasa di getOptions() akan dikirim sebagai teks, bukan
     * fungsi. Sumbu Y disingkat ke "rb"/"jt" karena rupiah penuh membuat
     * labelnya sangat lebar; tooltip tetap menampilkan angka utuh.
     */
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
                y: {
                    formatter: function (val) {
                        return 'Rp' + new Intl.NumberFormat('id-ID').format(val)
                    }
                }
            }
        }
        JS);
    }
}
