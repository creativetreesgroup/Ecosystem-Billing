<?php

use App\Filament\Resources\Settings\Pages\EditSetting;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

/**
 * Setting bukan data biasa: Setting::get('billing_increment_minutes') dipakai
 * di jalur penagihan Open Play. Kalau barisnya hilang, perhitungan diam-diam
 * jatuh ke nilai default dan tidak ada yang tahu tagihannya sudah berubah.
 * SettingPolicy melarangnya; test ini yang menjaga larangan itu benar-benar
 * sampai ke tombol di layar.
 */
test('the settings screen offers no way to delete a setting', function () {
    $setting = Setting::factory()->create();

    Livewire::actingAs(User::factory()->owner()->create())
        ->test(EditSetting::class, ['record' => $setting->getRouteKey()])
        ->assertActionDoesNotExist('delete');

    expect(User::factory()->owner()->create()->can('delete', $setting))->toBeFalse();
});
