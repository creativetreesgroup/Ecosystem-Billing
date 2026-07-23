<?php

use App\Domain\Settings\SettingKey;
use App\Filament\Resources\Settings\Pages\EditSetting;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

/**
 * Form pengaturan digerakkan enum: menambah SettingKey bertipe baru berarti
 * merender komponen yang belum pernah dipakai halaman ini. Kalau komponennya
 * salah nama atau tidak cocok dengan penyimpanan array `value.value`, jam buka
 * dapur jadi tidak bisa diubah sama sekali — dan itu baru ketahuan saat pemilik
 * outlet mencoba.
 */
beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
});

test('the owner can set the kitchen hours from the panel', function () {
    Setting::put(SettingKey::MenuOpenTime, '00:00');
    $setting = Setting::query()->where('key', SettingKey::MenuOpenTime)->sole();

    Livewire::actingAs($this->owner)
        ->test(EditSetting::class, ['record' => $setting->getKey()])
        ->assertSuccessful()
        ->fillForm(['value' => ['value' => '10:00']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Setting::get(SettingKey::MenuOpenTime))->toContain('10:00');
});

test('the owner can switch food ordering off from the panel', function () {
    Setting::put(SettingKey::MenuOrderingEnabled, 1);
    $setting = Setting::query()->where('key', SettingKey::MenuOrderingEnabled)->sole();

    Livewire::actingAs($this->owner)
        ->test(EditSetting::class, ['record' => $setting->getKey()])
        ->assertSuccessful()
        ->fillForm(['value' => ['value' => false]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((int) Setting::get(SettingKey::MenuOrderingEnabled))->toBe(0);
});

/** Jam istirahat BOLEH kosong — artinya tidak ada istirahat, bukan galat. */
test('an empty break time is accepted', function () {
    Setting::put(SettingKey::MenuBreakStartTime, '15:00');
    $setting = Setting::query()->where('key', SettingKey::MenuBreakStartTime)->sole();

    Livewire::actingAs($this->owner)
        ->test(EditSetting::class, ['record' => $setting->getKey()])
        ->fillForm(['value' => ['value' => null]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((string) Setting::get(SettingKey::MenuBreakStartTime))->toBe('');
});
