<?php

use App\Domain\Settings\SettingKey;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Setelan dibaca di banyak jalur panas, jadi disimpan di cache. Bukti hit-nya:
 * setelah dibaca sekali, menghapus barisnya LANGSUNG di DB (menembus event model
 * yang membuang cache) tidak mengubah hasil get() — artinya jawabannya datang
 * dari cache, bukan query ulang.
 */
test('get() serves a setting from cache without re-querying', function () {
    Setting::put(SettingKey::WarningBeforeMinutes, 7);
    expect(Setting::get(SettingKey::WarningBeforeMinutes))->toBe(7);

    DB::table('settings')->where('key', SettingKey::WarningBeforeMinutes->value)->delete();

    expect(Setting::get(SettingKey::WarningBeforeMinutes))->toBe(7);
});

/**
 * Invalidasi agresif & tepat: perubahan lewat put() MAUPUN penyimpanan model
 * (jalur form Filament) langsung membuang cache barisnya — nilai basi tak pernah
 * bertahan lebih dari satu penyimpanan.
 */
test('changing a setting busts its cache immediately', function () {
    Setting::put(SettingKey::WarningBeforeMinutes, 5);
    expect(Setting::get(SettingKey::WarningBeforeMinutes))->toBe(5);

    Setting::put(SettingKey::WarningBeforeMinutes, 9);
    expect(Setting::get(SettingKey::WarningBeforeMinutes))->toBe(9);

    Setting::query()->where('key', SettingKey::WarningBeforeMinutes)->first()
        ->update(['value' => ['value' => 12]]);
    expect(Setting::get(SettingKey::WarningBeforeMinutes))->toBe(12);
});
