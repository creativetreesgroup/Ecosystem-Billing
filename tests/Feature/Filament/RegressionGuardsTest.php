<?php

use App\Domain\Billing\PaymentMethod;
use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\DeviceAlertStatus;
use App\Domain\Devices\DeviceAlertType;
use App\Domain\Sessions\Actions\CompleteSessionAction;
use App\Domain\Sessions\Actions\StartSessionAction;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Filament\Pages\SalesReport;
use App\Filament\Resources\RentalSessions\Pages\ListRentalSessions;
use App\Filament\Resources\Units\Pages\EditUnit;
use App\Models\DeviceAlert;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * Laporan default dulu dibuat mount() memakai now() (UTC) padahal range()
 * membacanya sebagai jam dinding outlet — antara 00:00-07:00 WIB tanggalnya
 * mundur sehari dan pendapatan malam yang baru terjadi hilang dari laporan.
 */
test('the default report range covers tonight even when opened after midnight WIB', function () {
    // 17:30 UTC = 20 Jul 00:30 WIB — outlet masih buka, hari WIB sudah berganti.
    Carbon::setTestNow(Carbon::parse('2026-07-19 17:30:00', 'UTC'));

    $owner = User::factory()->owner()->create();

    Livewire::actingAs($owner)->test(SalesReport::class)
        // DatePicker melengkapi bagian jam; yang dijaga di sini bagian TANGGALnya
        // — sebelum perbaikan nilainya 2026-07-19 (mundur sehari).
        ->assertSet('data.end_date', fn (string $v): bool => str_starts_with($v, '2026-07-20'))
        ->assertSet('data.start_date', fn (string $v): bool => str_starts_with($v, '2026-07-01'));

    Carbon::setTestNow();
});

/**
 * Filament tidak menyimpan field tersembunyi. Mengubah unit ke driver Manual
 * dulu meninggalkan control_ref lama; dengan unique index, TV itu terkunci —
 * tidak dipakai unit ini, tapi juga tidak bisa dipakai unit lain.
 */
test('switching a unit to the manual driver releases its control_ref', function () {
    $owner = User::factory()->owner()->create();
    $unit = Unit::factory()->create([
        'control_driver' => ControlDriver::Tasmota,
        'control_ref' => 'plug-ps01',
    ]);

    Livewire::actingAs($owner)
        ->test(EditUnit::class, ['record' => $unit->getRouteKey()])
        ->fillForm(['control_driver' => ControlDriver::Manual->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($unit->fresh()->control_ref)->toBeNull();

    // Dan TV-nya benar-benar bebas dipakai unit lain.
    $other = Unit::factory()->create([
        'outlet_id' => $unit->outlet_id,
        'control_driver' => ControlDriver::Tasmota,
        'control_ref' => 'plug-ps01',
    ]);
    expect($other->exists)->toBeTrue();
});

/**
 * Home Assistant mati semalaman tidak boleh membuat satu alert per penutupan
 * sesi — kasir akan tenggelam dan berhenti membaca alert sama sekali.
 */
test('a repeated device problem raises one open alert, not one per occurrence', function () {
    $unit = Unit::factory()->create();

    foreach (range(1, 5) as $i) {
        DeviceAlert::raiseOnce($unit->id, DeviceAlertType::PowerOffFailed, "percobaan {$i}");
    }

    expect(DeviceAlert::where('unit_id', $unit->id)->count())->toBe(1);
});

test('a new alert is raised again once the previous one has been acknowledged', function () {
    $unit = Unit::factory()->create();

    $first = DeviceAlert::raiseOnce($unit->id, DeviceAlertType::PowerOffFailed, 'pertama');
    $first->update(['status' => DeviceAlertStatus::Acknowledged]);

    DeviceAlert::raiseOnce($unit->id, DeviceAlertType::PowerOffFailed, 'kedua');

    expect(DeviceAlert::where('unit_id', $unit->id)->count())->toBe(2);
});

/**
 * Alasan void adalah catatan audit untuk pembatalan UANG. Setelah field-nya
 * jadi RichEditor, required() saja tidak lagi cukup: editor yang cuma
 * di-klik lalu ditinggal mengirim "<p></p>" — secara teknis terisi, tapi
 * kosong bagi manusia yang membaca riwayat enam bulan kemudian.
 */
test('an empty rich editor is not accepted as a void reason', function () {
    $owner = User::factory()->owner()->create();
    $unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);

    $session = app(StartSessionAction::class)->handle($unit, $owner, SessionType::Open);
    app(CompleteSessionAction::class)->handle($session, PaymentMethod::Cash);

    $action = TestAction::make('void')->table($session);

    Livewire::actingAs($owner)->test(ListRentalSessions::class)
        ->mountAction($action)
        ->setActionData(['reason' => '<p></p>'])
        ->callMountedAction()
        ->assertHasActionErrors(['reason']);

    expect($session->refresh()->status)->toBe(SessionStatus::Completed);
});
