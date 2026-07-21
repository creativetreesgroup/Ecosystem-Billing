<?php

use App\Domain\Devices\DeviceAlertType;
use App\Domain\Devices\Events\DeviceAlertRaised;
use App\Models\DeviceAlert;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/**
 * Alert perangkat harus mengendap di lonceng panel, bukan cuma berkedip di grid
 * lalu hilang saat halaman ditutup — jadi memunculkan satu alert menulis TEPAT
 * satu notifikasi tersimpan untuk tiap staf AKTIF (yang nonaktif dilewati).
 *
 * Diuji lewat jalur nyata (DeviceAlert::create → event → listener) supaya sekali
 * jalan ikut menjaga dari listener yang tak sengaja terdaftar dua kali — dulu
 * bikin tiap alert menulis dua notifikasi.
 */
test('raising a device alert notifies every active panel user exactly once', function () {
    $activeOwner = User::factory()->owner()->create(['is_active' => true]);
    $activeKasir = User::factory()->create(['is_active' => true]);
    $inactive = User::factory()->create(['is_active' => false]);

    $unit = Unit::factory()->create(['code' => 'PS-07']);
    DeviceAlert::create([
        'unit_id' => $unit->id,
        'type' => DeviceAlertType::DeviceOffline,
        'message' => 'TV tidak menjawab',
    ]);

    expect($activeOwner->fresh()->notifications()->count())->toBe(1)
        ->and($activeKasir->fresh()->notifications()->count())->toBe(1)
        ->and($inactive->fresh()->notifications()->count())->toBe(0);

    $data = $activeOwner->fresh()->notifications()->first()->data;
    expect($data['title'] ?? '')->toContain('PS-07')
        ->and($data['body'] ?? '')->toBe('TV tidak menjawab');
});

test('the device-alert notification listener is registered exactly once', function () {
    expect(Event::getListeners(DeviceAlertRaised::class))->toHaveCount(1);
});
