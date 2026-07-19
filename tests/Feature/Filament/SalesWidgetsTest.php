<?php

use App\Domain\Billing\SalesSummary;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\SalesReport;
use App\Filament\Widgets\SalesRevenueChart;
use App\Filament\Widgets\SalesStatsWidget;
use App\Filament\Widgets\UnitGridWidget;
use App\Models\RentalSession;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->unit = Unit::factory()->create([
        'unit_type_id' => UnitType::factory()->create(['name' => 'Non-VIP'])->id,
    ]);
});

function completedOn(Unit $unit, string $wibDateTime, int $amount): RentalSession
{
    return RentalSession::factory()->completed()->create([
        'unit_id' => $unit->id,
        'total_amount' => $amount,
        'started_at' => Carbon::parse($wibDateTime, 'Asia/Jakarta')->utc(),
        'ended_at' => Carbon::parse($wibDateTime, 'Asia/Jakarta')->addHour()->utc(),
    ]);
}

test('the stats widget reports the totals for the range it is given', function () {
    completedOn($this->unit, '2026-05-04 14:00', 30000);
    completedOn($this->unit, '2026-05-04 15:00', 20000);
    completedOn($this->unit, '2026-06-01 14:00', 999000); // di luar rentang

    Livewire::actingAs($this->owner)->test(SalesStatsWidget::class, [
        'startDate' => '2026-05-01',
        'endDate' => '2026-05-31',
    ])
        ->assertSee('Rp 50.000')
        ->assertSee('Rp 25.000')      // rata-rata per sesi
        ->assertDontSee('Rp 999.000');
});

/**
 * Grafik memakai satu titik per hari TERMASUK hari kosong — garis yang
 * melompati hari tanpa transaksi membuat tren terlihat lebih ramai dari
 * kenyataan.
 */
test('the chart emits one point per day, including days with no sales', function () {
    completedOn($this->unit, '2026-05-02 14:00', 15000);

    $chart = Livewire::actingAs($this->owner)->test(SalesRevenueChart::class, [
        'startDate' => '2026-05-01',
        'endDate' => '2026-05-04',
    ])->get('options');

    expect($chart['series'][0]['data'])->toBe([0, 15000, 0, 0])
        ->and($chart['xaxis']['categories'])->toBe(['01 May', '02 May', '03 May', '04 May']);
});

test('both widgets follow the range broadcast by the report page', function () {
    completedOn($this->unit, '2026-05-02 14:00', 15000);
    completedOn($this->unit, '2026-09-09 14:00', 77000);

    Livewire::actingAs($this->owner)->test(SalesStatsWidget::class, [
        'startDate' => '2026-05-01', 'endDate' => '2026-05-31',
    ])
        ->assertSee('Rp 15.000')
        ->dispatch('sales-range-updated', startDate: '2026-09-01', endDate: '2026-09-30')
        ->assertSee('Rp 77.000')
        ->assertDontSee('Rp 15.000');
});

/**
 * Widget laporan tidak boleh ikut muncul di dasbor kasir. Dijaga lewat daftar
 * eksplisit di Dashboard::getWidgets(), BUKAN canView() — canView() dipakai
 * untuk abort(403) saat mount, jadi widgetnya malah mati di halaman Laporan.
 */
test('the dashboard shows only the unit grid, not the report widgets', function () {
    expect((new Dashboard)->getWidgets())->toBe([UnitGridWidget::class]);

    Livewire::actingAs($this->owner)->test(SalesStatsWidget::class)->assertOk();
});

/**
 * Rincian per metode bayar & tipe unit harus punya baris TOTAL, dan totalnya
 * dihitung di SalesSummary (bukan dijumlah ulang di UI) supaya dijamin cocok
 * dengan baris-baris di atasnya.
 */
test('each breakdown ends with a total row that matches its rows', function () {
    completedOn($this->unit, '2026-05-02 14:00', 30000);
    completedOn($this->unit, '2026-05-03 14:00', 20000);

    $summary = new SalesSummary('2026-05-01', '2026-05-31');

    expect($summary->revenueByPaymentMethod())->toHaveKey('TOTAL (2 sesi)')
        ->and($summary->revenueByPaymentMethod()['TOTAL (2 sesi)'])->toBe('Rp 50.000')
        ->and($summary->revenueByUnitType()['TOTAL (2 sesi)'])->toBe('Rp 50.000');
});

test('an empty range shows no total row at all', function () {
    $summary = new SalesSummary('2026-05-01', '2026-05-31');

    expect($summary->revenueByPaymentMethod())->toBe([]);
});

/**
 * Rincian hidup di halaman (bukan widget), jadi halamannya sendiri yang harus
 * menyegarkan diri saat ada sesi selesai — kalau tidak, angkanya diam sampai
 * owner memuat ulang halaman.
 */
test('the report page refreshes its breakdowns when a session ends', function () {
    $page = Livewire::actingAs($this->owner)->test(SalesReport::class)
        ->set('data.start_date', '2026-05-01')
        ->set('data.end_date', '2026-05-31')
        ->assertDontSee('Rp 64.000');

    completedOn($this->unit, '2026-05-10 14:00', 64000);

    $page->dispatch('echo-private:panel.units,.session.ended')
        ->assertSee('Rp 64.000');
});
