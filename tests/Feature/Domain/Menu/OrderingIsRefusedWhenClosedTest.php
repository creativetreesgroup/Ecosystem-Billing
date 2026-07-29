<?php

use App\Domain\Menu\Actions\PlaceMenuOrderAction;
use App\Domain\Settings\SettingKey;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\MenuOrder;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Menyembunyikan menu di layar BUKAN penutupan. Tab kios cuma UI, dan request
 * Livewire bisa dipanggil langsung — kalau larangannya hanya di tampilan, dapur
 * tetap menerima pesanan pukul tiga pagi dan saldo pelanggan tetap tertarik.
 * Penjaganya harus ada di jalur uang.
 */
beforeEach(function () {
    User::factory()->owner()->create();
    $this->customer = Customer::factory()->create(['balance' => 100_000]);
    $this->unit = Unit::factory()->create();
    $this->item = MenuItem::factory()->create(['price' => 15_000, 'is_active' => true]);

    Setting::put(SettingKey::MenuOrderingEnabled, 1);
    Setting::put(SettingKey::MenuOpenTime, '10:00');
    Setting::put(SettingKey::MenuCloseTime, '22:00');
});

afterEach(fn () => Carbon::setTestNow());

test('an order placed outside opening hours is refused and nothing is charged', function () {
    Carbon::setTestNow('2026-07-23 03:00');

    expect(fn () => app(PlaceMenuOrderAction::class)->handle($this->customer, $this->unit, [$this->item->id => 1]))
        ->toThrow(InvalidArgumentException::class);

    expect($this->customer->fresh()->balance)->toBe(100_000)
        ->and(MenuOrder::count())->toBe(0);
});

test('an order placed during the break is refused', function () {
    Setting::put(SettingKey::MenuBreakStartTime, '15:00');
    Setting::put(SettingKey::MenuBreakEndTime, '16:00');
    Carbon::setTestNow('2026-07-23 15:30');

    expect(fn () => app(PlaceMenuOrderAction::class)->handle($this->customer, $this->unit, [$this->item->id => 1]))
        ->toThrow(InvalidArgumentException::class);

    expect($this->customer->fresh()->balance)->toBe(100_000);
});

test('an order is refused while the owner has ordering switched off', function () {
    Setting::put(SettingKey::MenuOrderingEnabled, 0);
    Carbon::setTestNow('2026-07-23 14:00');

    expect(fn () => app(PlaceMenuOrderAction::class)->handle($this->customer, $this->unit, [$this->item->id => 1]))
        ->toThrow(InvalidArgumentException::class);

    expect($this->customer->fresh()->balance)->toBe(100_000);
});

test('an order inside opening hours still goes through', function () {
    Carbon::setTestNow('2026-07-23 14:00');

    app(PlaceMenuOrderAction::class)->handle($this->customer, $this->unit, [$this->item->id => 1]);

    expect($this->customer->fresh()->balance)->toBe(85_000)
        ->and(MenuOrder::count())->toBe(1);
});

/** Pelanggan harus DIBERI TAHU, bukan cuma menemukan menunya kosong. */
test('the kiosk explains the break and when the kitchen reopens', function () {
    Setting::put(SettingKey::MenuBreakStartTime, '15:00');
    Setting::put(SettingKey::MenuBreakEndTime, '16:00');
    Carbon::setTestNow('2026-07-23 15:30');

    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('tab', 'order')
        ->assertSee('istirahat')
        ->assertSee('16:00')
        // Menunya tidak ditawarkan sama sekali selagi tutup.
        ->assertDontSee($this->item->name);
});

test('the kiosk shows the menu again once the kitchen is open', function () {
    Carbon::setTestNow('2026-07-23 14:00');

    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('tab', 'order')
        ->assertSee($this->item->name);
});
