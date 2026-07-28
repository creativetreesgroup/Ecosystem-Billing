<?php

use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\DeviceManager;
use App\Domain\Devices\IntegrationKey;
use App\Domain\Devices\PowerState;
use App\Models\Integration;
use App\Models\RentalSession;
use App\Models\Unit;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

/**
 * tv:doctor adalah alat yang dipegang teknisi sambil berdiri di depan TV yang
 * baru dipasang. Nilainya seluruhnya terletak pada satu hal: ia harus GAGAL
 * ketika pemasangannya memang salah. Alat diagnosa yang selalu hijau lebih
 * berbahaya daripada tidak ada alat sama sekali — ia mengubah "belum diperiksa"
 * menjadi "sudah diperiksa dan aman".
 */
beforeEach(function () {
    // APP_URL bawaan pengujian adalah localhost — justru salah satu kegagalan
    // yang dicari perintah ini. Dibuat benar di sini supaya tiap test hanya
    // merusak satu hal.
    URL::forceRootUrl('http://192.168.1.6:8001');

    Integration::query()->where('key', IntegrationKey::HomeAssistant)->delete();

    Integration::factory()->create([
        'key' => IntegrationKey::HomeAssistant,
        'base_url' => 'http://ha.test:8123',
        'token' => 'token-uji',
        'is_active' => true,
    ]);
});

function unitTv(array $overrides = []): Unit
{
    return Unit::factory()->create([
        'control_driver' => ControlDriver::HomeAssistant,
        'control_ref' => 'media_player.tv_uji',
        ...$overrides,
    ]);
}

function homeAssistant(string $state = 'on', bool $perintahBerhasil = true): void
{
    Http::fake([
        'ha.test:8123/api/states/*' => Http::response(['state' => $state]),
        'ha.test:8123/api/services/*' => Http::response([], $perintahBerhasil ? 200 : 500),
    ]);
}

test('a properly installed TV passes every step', function () {
    unitTv();
    homeAssistant();

    $this->artisan('tv:doctor')->assertSuccessful();
});

/**
 * Regresi DeviceManager::attempt(). Tipe kembaliannya dulu ?CommandResult,
 * sehingga membungkus state() selalu menghasilkan null — dan setiap TV yang
 * sehat terbaca "tidak terhubung". Kegagalan seperti ini tidak berisik: ia
 * hanya membuat orang berhenti mempercayai alat diagnosanya.
 */
test('reading state through the safe wrapper returns the state, not null', function () {
    $unit = unitTv();
    homeAssistant('playing');

    $state = app(DeviceManager::class)
        ->attempt($unit, fn ($driver) => $driver->state($unit));

    expect($state)->toBe(PowerState::On);
});

/**
 * Kegagalan pemasangan paling sering, dan paling sulit dilihat: Home Assistant
 * tetap menjawab 200, TV tetap "terhubung", tapi layarnya kosong selamanya
 * karena TV mencari gambar QR pada dirinya sendiri.
 */
test('an APP_URL pointing at localhost is caught before anyone blames the TV', function () {
    URL::forceRootUrl('http://localhost');
    unitTv();
    homeAssistant();

    $this->artisan('tv:doctor')->assertFailed();
});

test('a unit with no entity paired is reported, not silently skipped', function () {
    unitTv(['control_ref' => null]);
    homeAssistant();

    $this->artisan('tv:doctor')->assertFailed();
});

test('a TV that does not answer fails the check', function () {
    unitTv();
    Http::fake(['ha.test:8123/*' => Http::response([], 500)]);

    $this->artisan('tv:doctor')->assertFailed();
});

/**
 * Home Assistant menjawab 200 untuk perintah yang tidak berefek apa pun, jadi
 * kegagalan cast yang JUJUR (status bukan 2xx) wajib tetap tertangkap.
 */
test('a cast the gateway refuses fails the check', function () {
    unitTv();
    homeAssistant(perintahBerhasil: false);

    $this->artisan('tv:doctor')->assertFailed();
});

test('a manual unit has nothing to test and never fails the run', function () {
    Unit::factory()->create(['control_driver' => ControlDriver::Manual, 'control_ref' => null]);

    $this->artisan('tv:doctor')->assertSuccessful();
});

test('an inactive unit is left out entirely', function () {
    unitTv(['is_active' => false]);

    $this->artisan('tv:doctor')->assertFailed()
        ->expectsOutputToContain('Tidak ada unit aktif');
});

/**
 * Uji daya mematikan TV. Bila pelanggan sedang bermain, itu berarti sesi
 * berbayar dipotong oleh perintah diagnosa — kerugian yang jauh lebih besar
 * daripada manfaat pemeriksaannya.
 */
test('a unit in use is never touched', function () {
    $unit = unitTv();
    RentalSession::factory()->for($unit)->create();
    homeAssistant();

    $this->artisan('tv:doctor --power --settle=0')->assertSuccessful();

    Http::assertNothingSent();
});

test('--power fails when the TV never actually reaches the state asked for', function () {
    unitTv();
    // Perintahnya diterima (200) tapi TV tetap standby — persis perilaku yang
    // membuat jawaban sukses tidak boleh dipercaya.
    homeAssistant(state: 'off');

    $this->artisan('tv:doctor --power --settle=0')->assertFailed();
});

test('--power passes when the TV really turns on and then goes to standby', function () {
    unitTv();

    // Urutan pembacaan: koneksi awal, sesudah powerOn, sesudah powerOff.
    // Closure biasa (bukan arrow fn) — arrow fn menangkap by-value, sehingga
    // urutannya tidak pernah maju dan TV terlihat menyala selamanya.
    $states = ['on', 'on', 'off'];
    Http::fake([
        'ha.test:8123/api/states/*' => function () use (&$states) {
            return Http::response(['state' => array_shift($states) ?? 'off']);
        },
        'ha.test:8123/api/services/*' => Http::response([]),
    ]);

    $this->artisan('tv:doctor --power --settle=0')->assertSuccessful();
});

test('--unit narrows the run to a single unit', function () {
    unitTv(['code' => 'PS-1']);
    unitTv(['code' => 'PS-2', 'control_ref' => null]);
    homeAssistant();

    $this->artisan('tv:doctor --unit=PS-1')->assertSuccessful();
    $this->artisan('tv:doctor --unit=PS-2')->assertFailed();
});
