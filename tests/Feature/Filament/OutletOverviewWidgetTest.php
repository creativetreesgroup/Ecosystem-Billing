<?php

use App\Domain\Billing\PaymentMethod;
use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\DeviceAlertStatus;
use App\Domain\Devices\DeviceAlertType;
use App\Domain\Sessions\Actions\StartSessionAction;
use App\Domain\Sessions\SessionType;
use App\Filament\Widgets\OutletOverviewWidget;
use App\Models\DeviceAlert;
use App\Models\RentalSession;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->kasir = User::factory()->create();
});

test('it counts units in use against the total of active units', function () {
    $busy = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    Unit::factory()->count(2)->create(['control_driver' => ControlDriver::Manual]);
    Unit::factory()->create(['control_driver' => ControlDriver::Manual, 'is_active' => false]);

    app(StartSessionAction::class)->handle($busy, $this->kasir, SessionType::Open);

    Livewire::actingAs($this->kasir)->test(OutletOverviewWidget::class)
        ->assertSee('1 / 3')            // unit nonaktif tidak dihitung
        ->assertSee('2 unit siap dipakai');
});

/**
 * "Pendapatan hari ini" dipakai kasir untuk mencocokkan laci saat tutup, jadi
 * batas harinya harus mengikuti jam dinding outlet — bukan UTC.
 */
test('todays revenue only counts sessions finished today in the outlet timezone', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-20 03:00', 'Asia/Jakarta'));

    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);

    // 01:00 WIB hari ini — masih jam operasional, harus IKUT dihitung.
    RentalSession::factory()->completed()->create([
        'unit_id' => $unit->id,
        'total_amount' => 25000,
        'payment_method' => PaymentMethod::Cash,
        'started_at' => Carbon::parse('2026-07-20 00:00', 'Asia/Jakarta')->utc(),
        'ended_at' => Carbon::parse('2026-07-20 01:00', 'Asia/Jakarta')->utc(),
    ]);

    // Kemarin — tidak boleh ikut.
    RentalSession::factory()->completed()->create([
        'unit_id' => $unit->id,
        'total_amount' => 90000,
        'started_at' => Carbon::parse('2026-07-19 20:00', 'Asia/Jakarta')->utc(),
        'ended_at' => Carbon::parse('2026-07-19 21:00', 'Asia/Jakarta')->utc(),
    ]);

    Livewire::actingAs($this->kasir)->test(OutletOverviewWidget::class)
        ->assertSee('Rp 25.000')
        ->assertDontSee('Rp 90.000');

    Carbon::setTestNow();
});

test('it reports only alerts that are still open', function () {
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);

    DeviceAlert::create(['unit_id' => $unit->id, 'type' => DeviceAlertType::DeviceOffline, 'message' => 'a']);
    DeviceAlert::create([
        'unit_id' => $unit->id,
        'type' => DeviceAlertType::StateMismatch,
        'message' => 'b',
        'status' => DeviceAlertStatus::Acknowledged,
    ]);

    Livewire::actingAs($this->kasir)->test(OutletOverviewWidget::class)
        ->assertSee('Belum ditangani — cek fisik');
});
