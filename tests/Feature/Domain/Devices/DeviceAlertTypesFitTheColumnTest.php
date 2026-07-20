<?php

use App\Domain\Devices\DeviceAlertType;
use App\Models\DeviceAlert;
use App\Models\Unit;

/**
 * Kolom device_alerts.type adalah ENUM MySQL. Menambah case baru di
 * DeviceAlertType tanpa menambahnya di migrasi membuat MySQL menolak dengan
 * "Data truncated" — dan karena alert dibuat dari dalam job antrean,
 * kegagalannya TIDAK terlihat di layar mana pun: TV gagal menyala, alert yang
 * seharusnya memberi tahu kasir justru ikut hilang, dan satu-satunya jejaknya
 * menumpuk diam-diam di failed_jobs.
 *
 * Terjadi sungguhan saat UAT dengan case PowerOnFailed yang baru.
 */
test('every alert type the code can raise actually fits the database column', function (DeviceAlertType $type) {
    $unit = Unit::factory()->create();

    DeviceAlert::raiseOnce($unit->id, $type, "Uji {$type->value}");

    expect(DeviceAlert::where('unit_id', $unit->id)->where('type', $type)->exists())->toBeTrue();
})->with(fn () => array_map(fn (DeviceAlertType $t) => [$t], DeviceAlertType::cases()));
