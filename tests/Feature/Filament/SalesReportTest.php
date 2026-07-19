<?php

use App\Domain\Billing\PaymentMethod;
use App\Filament\Pages\SalesReport;
use App\Filament\Widgets\SalesStatsWidget;
use App\Models\RentalSession;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
});

function reportUnit(string $unitTypeName): Unit
{
    return Unit::factory()->create([
        'unit_type_id' => UnitType::factory()->create(['name' => $unitTypeName])->id,
    ]);
}

test('it summarizes completed sessions within the selected date range only', function () {
    $nonVip = reportUnit('Non-VIP');
    $vip = reportUnit('VIP');

    RentalSession::factory()->completed()->create([
        'unit_id' => $nonVip->id,
        'payment_method' => PaymentMethod::Cash,
        'total_amount' => 10000,
        'started_at' => '2026-01-10 14:00:00',
        'ended_at' => '2026-01-10 15:00:00',
    ]);

    RentalSession::factory()->completed()->create([
        'unit_id' => $vip->id,
        'payment_method' => PaymentMethod::Qris,
        'total_amount' => 20000,
        'started_at' => '2026-01-10 14:30:00',
        'ended_at' => '2026-01-10 15:30:00',
    ]);

    // Di luar rentang tanggal — harus diabaikan.
    RentalSession::factory()->completed()->create([
        'unit_id' => $nonVip->id,
        'total_amount' => 999999,
        'ended_at' => '2026-02-05 10:00:00',
    ]);

    // Masih aktif, belum final — bukan pendapatan, harus diabaikan.
    RentalSession::factory()->create([
        'unit_id' => $nonVip->id,
        'total_amount' => 500000,
        'started_at' => '2026-01-15 10:00:00',
    ]);

    Livewire::actingAs($this->owner)->test(SalesReport::class)
        ->set('data.start_date', '2026-01-01')
        ->set('data.end_date', '2026-01-31')
        ->assertSee('Rp 30.000')
        ->assertSee('Tunai (1 sesi)')
        ->assertSee('QRIS (1 sesi)')
        ->assertSee('Non-VIP (1 sesi)')
        ->assertSee('VIP (1 sesi)')
        ->assertSeeInOrder(['Rp 10.000', 'Rp 20.000'])
        ->assertDontSee('Rp 999.999')
        ->assertDontSee('Rp 500.000');
});

test('it identifies the hour with the most sessions', function () {
    $unit = reportUnit('Non-VIP');

    // Ditulis dalam jam dinding outlet (WIB) lalu ->utc() eksplisit: kolom
    // datetime menyimpan UTC, dan Carbon ber-timezone Jakarta kalau tidak
    // dikonversi akan menulis jam dindingnya apa adanya. Laporan harus
    // melaporkan kembali jam WIB-nya.
    RentalSession::factory()->completed()->count(2)->create([
        'unit_id' => $unit->id,
        'started_at' => Carbon::parse('2026-03-05 20:10', 'Asia/Jakarta')->utc(),
        'ended_at' => Carbon::parse('2026-03-05 21:00', 'Asia/Jakarta')->utc(),
    ]);

    RentalSession::factory()->completed()->create([
        'unit_id' => $unit->id,
        'started_at' => Carbon::parse('2026-03-05 09:00', 'Asia/Jakarta')->utc(),
        'ended_at' => Carbon::parse('2026-03-05 09:30', 'Asia/Jakarta')->utc(),
    ]);

    // Jam tersibuk kini dirender SalesStatsWidget (kartu statistik bawaan
    // Filament), bukan lagi di HTML halaman — jadi diuji langsung ke widgetnya.
    Livewire::actingAs($this->owner)->test(SalesStatsWidget::class, [
        'startDate' => '2026-03-01',
        'endDate' => '2026-03-31',
    ])->assertSee('20:00 – 21:00');
});

test('csv export contains the exact rows for the selected range', function () {
    $unit = reportUnit('Non-VIP');

    $session = RentalSession::factory()->completed()->create([
        'unit_id' => $unit->id,
        'customer_name' => 'Budi',
        'payment_method' => PaymentMethod::Cash,
        'total_amount' => 15000,
        'started_at' => '2026-04-01 10:00:00',
        'ended_at' => '2026-04-01 11:00:00',
    ]);

    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, ['Tanggal Selesai', 'Unit', 'Tipe Unit', 'Pelanggan', 'Tipe Sesi', 'Metode Bayar', 'Total (Rp)']);
    fputcsv($handle, [
        $session->ended_at->setTimezone('Asia/Jakarta')->format('Y-m-d H:i'),
        $unit->code,
        'Non-VIP',
        'Budi',
        $session->type->value,
        'cash',
        15000,
    ]);
    rewind($handle);
    $expected = stream_get_contents($handle);
    fclose($handle);

    Livewire::actingAs($this->owner)->test(SalesReport::class)
        ->set('data.start_date', '2026-04-01')
        ->set('data.end_date', '2026-04-30')
        ->callAction('exportCsv')
        ->assertFileDownloaded('laporan-2026-04-01-2026-04-30.csv', $expected);
});

// Sesi jam 06:00 WIB tanggal 2 Juli tersimpan sebagai 23:00 UTC tanggal 1 Juli.
// Kalau batas hari dihitung di UTC, sesi ini bocor ke laporan tanggal 1.
test('day boundaries follow the outlet wall clock, not UTC', function () {
    $unit = reportUnit('Non-VIP');

    RentalSession::factory()->completed()->create([
        'unit_id' => $unit->id,
        'total_amount' => 77000,
        'started_at' => Carbon::parse('2026-07-02 05:00', 'Asia/Jakarta')->utc(),
        'ended_at' => Carbon::parse('2026-07-02 06:00', 'Asia/Jakarta')->utc(),
    ]);

    Livewire::actingAs($this->owner)->test(SalesReport::class)
        ->set('data.start_date', '2026-07-01')
        ->set('data.end_date', '2026-07-01')
        ->assertDontSee('Rp 77.000');

    Livewire::actingAs($this->owner)->test(SalesReport::class)
        ->set('data.start_date', '2026-07-02')
        ->set('data.end_date', '2026-07-02')
        ->assertSee('Rp 77.000');
});
