<?php

use App\Domain\Settings\SettingKey;
use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Identitas usaha dulu dipaku ke APP_NAME, sehingga menggantinya menuntut
 * pemilik outlet masuk ke server dan menyunting berkas. Yang diuji di sini
 * bukan "apakah nama bisa disimpan", melainkan tiga hal yang kalau salah
 * merugikan: nama kosong tidak boleh menghasilkan panel tanpa judul, aset
 * harus benar-benar tersaji lewat Docker, dan route penyajinya tidak boleh
 * bisa dipakai membaca pengaturan lain.
 */
test('brand name falls back to the configured app name when left empty', function () {
    Setting::put(SettingKey::BusinessName, '');

    expect(Setting::brandName())->toBe(config('app.name'));
});

test('brand name uses the business name once it is filled in', function () {
    Setting::put(SettingKey::BusinessName, 'Creative Trees');

    expect(Setting::brandName())->toBe('Creative Trees');
});

test('an uploaded logo is served through the application', function () {
    Storage::fake('local');
    $path = UploadedFile::fake()->image('logo.png')->store('brand', 'local');
    Setting::put(SettingKey::BrandLogo, $path);

    $url = Setting::brandAssetUrl(SettingKey::BrandLogo);

    expect($url)->not->toBeNull();

    $this->get($url)->assertOk();
});

test('the asset url changes when the file changes so browsers refetch it', function () {
    Storage::fake('local');
    Setting::put(SettingKey::BrandLogo, UploadedFile::fake()->image('a.png')->store('brand', 'local'));
    $first = Setting::brandAssetUrl(SettingKey::BrandLogo);

    Setting::put(SettingKey::BrandLogo, UploadedFile::fake()->image('b.png')->store('brand', 'local'));
    $second = Setting::brandAssetUrl(SettingKey::BrandLogo);

    // Tanpa sidik jari di URL, pemilik outlet mengganti logo dan tidak melihat
    // perubahan apa pun sampai cache browser kedaluwarsa sendiri.
    expect($second)->not->toBe($first);
});

test('an unset brand asset yields no url instead of a broken link', function () {
    Setting::put(SettingKey::BrandFavicon, '');

    expect(Setting::brandAssetUrl(SettingKey::BrandFavicon))->toBeNull();
});

test('the asset route refuses to serve any setting that is not a brand asset', function () {
    // Inti keamanannya. Tanpa daftar-putih isBrandAsset(), route yang menerima
    // nama pengaturan bisa dipakai membaca nilai pengaturan APA PUN sebagai
    // berkas — termasuk nomor rekening tujuan transfer.
    Setting::put(SettingKey::TransferAccountNumber, '1234567890');

    $this->get('/brand/'.SettingKey::TransferAccountNumber->value)->assertNotFound();
    $this->get('/brand/business_name')->assertNotFound();
    $this->get('/brand/tidak-ada')->assertNotFound();
});

test('a brand asset whose file is missing returns 404 rather than an error page', function () {
    Storage::fake('local');
    Setting::put(SettingKey::BrandLogo, 'brand/sudah-terhapus.png');

    $this->get('/brand/'.SettingKey::BrandLogo->value)->assertNotFound();
    expect(Setting::brandAssetUrl(SettingKey::BrandLogo))->toBeNull();
});

test('brand assets are reachable without logging in', function () {
    Storage::fake('local');
    Setting::put(SettingKey::BrandFavicon, UploadedFile::fake()->image('favicon.png')->store('brand', 'local'));

    // Favicon diminta browser sebelum siapa pun masuk; di balik auth berarti
    // tab selalu menampilkan ikon bawaan.
    $this->assertGuest();
    $this->get(Setting::brandAssetUrl(SettingKey::BrandFavicon))->assertOk();
});
