<?php

use App\Domain\Devices\IntegrationKey;
use App\Domain\Settings\SettingKey;
use App\Models\Integration;
use App\Models\Setting;
use Database\Seeders\DefaultConfigurationSeeder;

/**
 * Seeder ini dijalankan entrypoint SETIAP kali container app menyala, jadi
 * yang diuji bukan "apakah ia mengisi tabel" melainkan "apakah ia aman
 * dijalankan berulang di outlet yang sudah beroperasi".
 */
test('every known setting and integration gets a visible row', function () {
    Setting::query()->delete();
    Integration::query()->delete();

    (new DefaultConfigurationSeeder)->run();

    expect(Setting::count())->toBe(count(SettingKey::cases()))
        ->and(Integration::count())->toBe(count(IntegrationKey::cases()));

    // Nilainya harus nilai yang benar-benar dipakai aplikasi, bukan angka lain.
    foreach (SettingKey::cases() as $key) {
        expect(Setting::get($key))->toBe($key->default());
    }
});

test('re-running never overwrites a value the owner has changed', function () {
    // Inti keamanan seeder ini. Setting::put() ber-updateOrCreate, dan memakai
    // method itu di entrypoint berarti tarif yang sudah disesuaikan owner
    // direset ke bawaan pada setiap restart container — diam-diam, sampai ada
    // pelanggan tertagih dengan angka yang salah.
    (new DefaultConfigurationSeeder)->run();

    Setting::put(SettingKey::TopUpAdminFee, 7_777);
    Setting::put(SettingKey::WarningBeforeMinutes, 15);

    (new DefaultConfigurationSeeder)->run();

    expect(Setting::get(SettingKey::TopUpAdminFee))->toBe(7_777)
        ->and(Setting::get(SettingKey::WarningBeforeMinutes))->toBe(15);
});

test('re-running never overwrites integration credentials', function () {
    (new DefaultConfigurationSeeder)->run();

    $integration = Integration::for(IntegrationKey::HomeAssistant);
    $integration->update([
        'base_url' => 'http://192.168.1.50:8123',
        'token' => 'token-milik-outlet',
        'is_active' => true,
    ]);

    (new DefaultConfigurationSeeder)->run();

    $integration->refresh();

    expect($integration->base_url)->toBe('http://192.168.1.50:8123')
        ->and($integration->token)->toBe('token-milik-outlet')
        ->and($integration->isUsable())->toBeTrue();
});

test('a freshly seeded integration is not usable until it is configured', function () {
    Integration::query()->delete();

    (new DefaultConfigurationSeeder)->run();

    // Fail-secure: baris yang terlihat di panel tidak boleh berarti integrasi
    // sudah menyala. Tanpa base_url dan token, kontrol perangkat tetap mati.
    foreach (Integration::all() as $integration) {
        expect($integration->isUsable())->toBeFalse();
    }
});

test('the seeder adds nothing beyond the keys the code knows about', function () {
    Setting::query()->delete();
    Integration::query()->delete();

    (new DefaultConfigurationSeeder)->run();

    $seeded = Setting::pluck('key')->map(fn ($key) => $key->value)->sort()->values();
    $known = collect(SettingKey::cases())->map(fn ($key) => $key->value)->sort()->values();

    expect($seeded->all())->toBe($known->all());
});
