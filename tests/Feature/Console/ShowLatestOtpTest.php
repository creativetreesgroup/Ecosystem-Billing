<?php

/**
 * Perintah bantu pengembangan yang MENYENTUH kredensial masuk — jadi yang
 * diuji bukan kerapian keluarannya, melainkan satu hal: ia menolak jalan di
 * produksi. Di sana kode tidak pernah ada di log, dan perintah yang seolah
 * bisa menunjukkannya hanya mengundang percobaan.
 */
test('it refuses to run in production', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('otp:latest')
        ->expectsOutputToContain('Tidak tersedia di produksi')
        ->assertFailed();
});

test('it runs outside production and says so when nothing is logged yet', function () {
    expect(app()->isProduction())->toBeFalse();

    $this->artisan('otp:latest', ['phone' => '080000000000'])->assertSuccessful();
});
