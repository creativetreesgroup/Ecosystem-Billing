<?php

use App\Domain\Billing\PaymentMethod;
use App\Domain\Devices\ControlDriver;
use App\Domain\Sessions\Actions\CompleteSessionAction;
use App\Domain\Sessions\Actions\StartSessionAction;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Filament\Widgets\UnitGridWidget;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->kasir = User::factory()->create();
});

function widgetUnit(int $hourlyRate = 6000): Unit
{
    return Unit::factory()->create([
        'control_driver' => ControlDriver::Manual,
        'unit_type_id' => UnitType::factory()->create(['hourly_rate' => $hourlyRate])->id,
    ]);
}

test('the dashboard loads for a kasir and lists the units', function () {
    $unit = widgetUnit();

    Livewire::actingAs($this->kasir)->test(UnitGridWidget::class)
        ->assertOk()
        ->assertSee($unit->code);
});

/**
 * Angka yang dilihat kasir di modal Stop & Bayar HARUS sama dengan yang
 * benar-benar ditagih. Dulu rumusnya disalin di dua tempat, jadi keduanya
 * bisa menyimpang diam-diam begitu salah satu diubah.
 */
test('the estimate shown to the kasir equals the amount actually charged', function () {
    Setting::create(['key' => 'billing_increment_minutes', 'value' => ['minutes' => 15]]);
    $unit = widgetUnit(hourlyRate: 9000);

    $session = app(StartSessionAction::class)->handle($unit, $this->kasir, SessionType::Open);
    $session->update(['started_at' => now()->subMinutes(37)]);

    $estimate = UnitGridWidget::estimateTotal($session->fresh());

    $completed = app(CompleteSessionAction::class)->handle($session->fresh(), PaymentMethod::Cash);

    expect($completed->total_amount)->toBe($estimate)
        ->and($completed->status)->toBe(SessionStatus::Completed);
});

test('a package session is estimated from its prepaid amounts, not the clock', function () {
    $unit = widgetUnit();
    $session = app(StartSessionAction::class)->handle(
        $unit,
        $this->kasir,
        SessionType::Package,
        package: Package::factory()->for($unit->unitType)->create(['duration_minutes' => 60, 'price' => 25000]),
        paymentMethod: PaymentMethod::Cash,
    );

    $session->update(['extra_amount' => 5000, 'started_at' => now()->subHours(9)]);

    expect(UnitGridWidget::estimateTotal($session->fresh()))->toBe(30000);
});
