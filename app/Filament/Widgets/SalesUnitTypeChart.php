<?php

namespace App\Filament\Widgets;

use App\Domain\Billing\SalesSummary;
use Filament\Support\RawJs;
use Leandrocfe\FilamentApexCharts\Widgets\ApexChartWidget;
use Livewire\Attributes\On;

/**
 * Pendapatan per tipe unit — pertanyaan investasi, bukan pertanyaan harian.
 *
 * Tren harian menjawab "bagaimana minggu ini?"; grafik ini menjawab yang
 * berbeda dan lebih mahal: "unit seperti apa yang layak ditambah?" VIP yang
 * jumlahnya separuh Non-VIP tapi menghasilkan setara berarti sesuatu, dan itu
 * tidak akan pernah terlihat dari deret harian yang mencampur semuanya.
 *
 * Batang mendatar, bukan donat: tipe unit dibandingkan BESARNYA satu sama lain,
 * dan panjang batang dibandingkan mata jauh lebih akurat daripada sudut juring.
 */
class SalesUnitTypeChart extends ApexChartWidget
{
    protected static ?string $chartId = 'salesUnitTypeChart';

    protected static ?string $heading = 'Pendapatan per tipe unit';

    protected static ?string $subheading = 'Mana yang paling menghasilkan pada rentang ini.';

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
        // Nilai dari SalesSummary berupa rupiah terformat; grafik butuh angka.
        $revenue = collect((new SalesSummary($this->startDate, $this->endDate))->revenueByUnitType())
            ->map(fn (string $formatted): int => (int) preg_replace('/\D/', '', $formatted))
            ->sortDesc();

        return [
            'chart' => [
                'type' => 'bar',
                'height' => max(220, 60 * max($revenue->count(), 1)),
                'toolbar' => ['show' => false],
            ],
            'series' => [[
                'name' => 'Pendapatan',
                'data' => $revenue->values()->all(),
            ]],
            'xaxis' => ['categories' => $revenue->keys()->all()],
            // Cognac panel, sama dengan warna primer Filament — grafik ini
            // membandingkan satu besaran, jadi satu warna sudah cukup; warna
            // berbeda per batang akan menyiratkan kategori yang tidak ada.
            'colors' => ['#f59e0b'],
            'plotOptions' => ['bar' => [
                'horizontal' => true,
                'borderRadius' => 3,
                'barHeight' => '55%',
            ]],
            'dataLabels' => ['enabled' => false],
            'legend' => ['show' => false],
            'grid' => ['borderColor' => '#374151', 'strokeDashArray' => 4],
        ];
    }

    protected function extraJsOptions(): ?RawJs
    {
        return RawJs::make(<<<'JS'
        {
            xaxis: {
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
