<?php

use App\Domain\Menu\MenuServiceHours;
use App\Domain\Menu\MenuServiceStatus;
use App\Domain\Settings\SettingKey;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Jam layanan dapur. Empat keadaan yang harus bisa dibedakan pelanggan di
 * layar: buka, istirahat, tutup, dan dimatikan pemilik.
 */
function openKitchen(string $open, string $close, string $breakStart = '', string $breakEnd = ''): void
{
    Setting::put(SettingKey::MenuOrderingEnabled, 1);
    Setting::put(SettingKey::MenuOpenTime, $open);
    Setting::put(SettingKey::MenuCloseTime, $close);
    Setting::put(SettingKey::MenuBreakStartTime, $breakStart);
    Setting::put(SettingKey::MenuBreakEndTime, $breakEnd);
}

afterEach(fn () => Carbon::setTestNow());

test('the kitchen is open inside its hours', function () {
    openKitchen('10:00', '22:00');
    Carbon::setTestNow('2026-07-23 14:00');

    expect(MenuServiceHours::status())->toBe(MenuServiceStatus::Open)
        ->and(MenuServiceHours::isOpen())->toBeTrue()
        ->and(MenuServiceHours::notice())->toBeNull();
});

test('before opening it is closed, and says when it opens', function () {
    openKitchen('10:00', '22:00');
    Carbon::setTestNow('2026-07-23 08:30');

    expect(MenuServiceHours::status())->toBe(MenuServiceStatus::Closed)
        ->and(MenuServiceHours::notice())->toContain('10:00');
});

test('after closing it is closed', function () {
    openKitchen('10:00', '22:00');
    Carbon::setTestNow('2026-07-23 22:30');

    expect(MenuServiceHours::status())->toBe(MenuServiceStatus::Closed);
});

/**
 * Rental PS tutup lewat tengah malam — 10:00 sampai 02:00 adalah jam yang
 * lumrah, dan perbandingan waktu yang naif menganggapnya tutup SEPANJANG hari
 * karena 10:00 > 02:00.
 */
test('hours that cross midnight stay open past midnight', function () {
    openKitchen('10:00', '02:00');

    Carbon::setTestNow('2026-07-23 23:30');
    expect(MenuServiceHours::status())->toBe(MenuServiceStatus::Open);

    Carbon::setTestNow('2026-07-24 01:30');
    expect(MenuServiceHours::status())->toBe(MenuServiceStatus::Open);

    Carbon::setTestNow('2026-07-24 03:00');
    expect(MenuServiceHours::status())->toBe(MenuServiceStatus::Closed);
});

test('the break window reports itself and when it ends', function () {
    openKitchen('10:00', '22:00', '15:00', '16:00');
    Carbon::setTestNow('2026-07-23 15:30');

    expect(MenuServiceHours::status())->toBe(MenuServiceStatus::Break)
        ->and(MenuServiceHours::isOpen())->toBeFalse()
        ->and(MenuServiceHours::notice())->toContain('16:00');
});

test('the kitchen reopens right after the break', function () {
    openKitchen('10:00', '22:00', '15:00', '16:00');
    Carbon::setTestNow('2026-07-23 16:00');

    expect(MenuServiceHours::status())->toBe(MenuServiceStatus::Open);
});

test('an empty break window means no break at all', function () {
    openKitchen('10:00', '22:00');
    Carbon::setTestNow('2026-07-23 15:30');

    expect(MenuServiceHours::status())->toBe(MenuServiceStatus::Open);
});

/** Saklar pemilik menang atas jam berapa pun — itu gunanya ada saklar. */
test('the master switch closes the kitchen even inside opening hours', function () {
    openKitchen('10:00', '22:00');
    Setting::put(SettingKey::MenuOrderingEnabled, 0);
    Carbon::setTestNow('2026-07-23 14:00');

    expect(MenuServiceHours::status())->toBe(MenuServiceStatus::Disabled)
        ->and(MenuServiceHours::notice())->not->toBeNull();
});

/**
 * Bawaan HARUS 24 jam. Outlet yang baru dipasang dan belum menyentuh
 * pengaturan ini tidak boleh mendapati menunya mati sendiri tengah malam —
 * dan suite-nya pun tidak boleh lulus/gagal tergantung jam berapa dijalankan.
 */
test('out of the box the kitchen is open around the clock', function () {
    foreach (['00:30', '09:00', '14:00', '23:59'] as $time) {
        Carbon::setTestNow('2026-07-23 '.$time);

        expect(MenuServiceHours::status())->toBe(MenuServiceStatus::Open);
    }
});
