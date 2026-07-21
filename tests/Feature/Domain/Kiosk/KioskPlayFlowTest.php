<?php

use App\Domain\Billing\Actions\StartKioskOpenPlayAction;
use App\Domain\Billing\PaymentMethod;
use App\Domain\Billing\PaymentStatus;
use App\Domain\Billing\SalesSummary;
use App\Domain\Devices\ControlDriver;
use App\Domain\Sessions\Actions\CompleteSessionAction;
use App\Domain\Sessions\Jobs\ExpireRentalSession;
use App\Domain\Sessions\SessionStatus;
use App\Domain\Sessions\SessionType;
use App\Domain\Wallet\Actions\PlayFromWalletAction;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Package;
use App\Models\Unit;
use App\Models\User;
use Livewire\Livewire;

/**
 * Alur "Mulai main" di kios, dari sudut pandang komponennya.
 *
 * Test-drive di TV sungguhan menemukan satu jebakan: pelanggan memilih paket
 * lalu langsung menekan "Mulai main", dan tidak terjadi apa-apa selain pesan
 * galat. Sebabnya radio paket dulu memakai wire:model.live — nilainya dikirim
 * ke server lewat request terpisah, dan play() berjalan sebelum nilai itu
 * sampai. Sekarang radionya deferred (wire:model biasa), jadi nilai paketnya
 * ikut terkirim bersama klik tombolnya.
 *
 * Livewire::set() lalu call() meniru urutan itu persis: state di-set, baru
 * aksinya dipanggil dalam request yang sama — seperti model deferred.
 */
beforeEach(function () {
    // Kios mengaitkan transaksinya ke owner sebagai operator — di sistem nyata
    // selalu ada satu.
    User::factory()->owner()->create();

    $this->unit = Unit::factory()->create(['control_driver' => ControlDriver::Manual]);
    $this->package = Package::factory()->for($this->unit->unitType)->create([
        'price' => 10_000,
        'duration_minutes' => 60,
        'is_active' => true,
    ]);

    $this->customer = Customer::factory()->create(['balance' => 50_000]);
});

test('choosing a package and pressing play in one go starts the session', function () {
    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('packageId', $this->package->id)
        ->call('play')
        ->assertHasNoErrors();

    expect($this->unit->fresh()->activeSession)->not->toBeNull()
        ->and($this->customer->fresh()->balance)->toBe(40_000);
});

test('a valid voucher discounts the package at the kiosk', function () {
    Discount::factory()->percentage(20)->create(['code' => 'HEMAT20']);

    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('packageId', $this->package->id)
        ->set('voucherCode', 'HEMAT20')
        ->call('play')
        ->assertHasNoErrors();

    // 10.000 − 20% = 8.000. Saldo 50k → 42k.
    expect($this->customer->fresh()->balance)->toBe(42_000);
});

test('the confirm modal previews a valid voucher and refuses a bad one', function () {
    Discount::factory()->percentage(20)->create(['code' => 'HEMAT20']);

    Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('playChoice', (string) $this->package->id)
        ->call('askPlay')
        ->set('voucherCode', 'HEMAT20')
        ->assertSee('dipakai')          // pratinjau berhasil
        ->set('voucherCode', 'NGACO')
        ->assertSee('tidak ditemukan'); // kode salah ditolak dengan pesan
});

/**
 * Kalau tidak ada paket yang dipilih, play() harus menolak dengan galat, bukan
 * memulai sesi gratis — dan pesannya bahasa Indonesia, bukan bawaan Laravel
 * "The paket field is required." yang sempat muncul di layar pelanggan.
 */
test('play without a package is refused, in Indonesian', function () {
    $component = Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->call('play')
        ->assertHasErrors('packageId');

    expect($component->errors()->first('packageId'))->toBe('Pilih paket dulu sebelum mulai main.');

    expect($this->unit->fresh()->activeSession)->toBeNull()
        ->and($this->customer->fresh()->balance)->toBe(50_000);
});

/**
 * Saat waktunya habis, sesi bayar-saldo harus benar-benar selesai — TV mati,
 * unit bebas lagi, dan uangnya masuk laporan.
 *
 * Ini menutup bug yang ditemukan saat uji coba di TV sungguhan: sesi kios
 * dibuat tanpa metode bayar, dan job expiry-nya menabrak "method cannot be
 * null" saat mencatat pembayaran penyelesaian. Akibatnya sesi tidak pernah
 * selesai, TV tidak pernah dimatikan, dan unit macet dianggap terpakai
 * selamanya — kegagalan yang tidak terlihat sampai satu jam kemudian.
 */
test('a wallet session actually completes when its time runs out', function () {
    $session = app(PlayFromWalletAction::class)
        ->handle($this->customer, $this->unit, $this->package);

    // Persis yang dilakukan penjadwal saat ends_at tiba.
    app(ExpireRentalSession::class, [
        'sessionId' => $session->id,
        'expiryToken' => $session->expiry_token,
    ])->handle(app(CompleteSessionAction::class));

    $session->refresh();

    expect($session->status)->toBe(SessionStatus::Completed)
        ->and($session->payment_method)->toBe(PaymentMethod::Wallet)
        ->and($this->unit->fresh()->activeSession)->toBeNull()
        ->and($session->payments()->sole()->status)->toBe(PaymentStatus::Paid);
});

/**
 * Uang saldo yang terpakai TETAP pendapatan, tapi TERPISAH dari tunai/QRIS/
 * transfer di laporan laci — karena uangnya sudah masuk laci saat isi saldo,
 * bukan saat main. Kalau ia terhitung sebagai tunai baru, laci tidak akan
 * pernah cocok.
 */
test('a played wallet session is revenue, filed under Saldo', function () {
    $session = app(PlayFromWalletAction::class)
        ->handle($this->customer, $this->unit, $this->package);

    app(ExpireRentalSession::class, [
        'sessionId' => $session->id,
        'expiryToken' => $session->expiry_token,
    ])->handle(app(CompleteSessionAction::class));

    $hari = now(SalesSummary::timezone())->toDateString();
    $laporan = new SalesSummary($hari, $hari);

    $perMetode = array_keys($laporan->revenueByPaymentMethod());

    expect($laporan->totalRevenue())->toBe(10_000)
        ->and(collect($perMetode)->contains(fn (string $label) => str_starts_with($label, 'Saldo')))->toBeTrue()
        ->and(collect($perMetode)->contains(fn (string $label) => str_starts_with($label, 'Tunai')))->toBeFalse();
});

/**
 * Konfirmasi pembelian: menekan "Mulai main" TIDAK langsung menagih — ia hanya
 * membuka konfirmasi. Uang baru berpindah setelah pelanggan menekan "Ya". Ini
 * jalur uang: kalau askPlay diam-diam menagih, satu ketukan tak sengaja saat
 * HP di saku sudah memotong saldo dan menyalakan TV.
 */
test('a play is confirmed before any money moves', function () {
    $component = Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('playChoice', (string) $this->package->id)
        ->call('askPlay')
        ->assertSet('confirm', 'play');

    // Baru dikonfirmasi — belum ditagih, TV belum menyala.
    expect($this->unit->fresh()->activeSession)->toBeNull()
        ->and($this->customer->fresh()->balance)->toBe(50_000);

    // Batal → tidak terjadi apa-apa.
    $component->call('cancelConfirm')->assertSet('confirm', null);
    expect($this->customer->fresh()->balance)->toBe(50_000);

    // Konfirmasi lagi lalu "Ya" → baru menagih & memulai sesi.
    $component->call('askPlay')->call('play')->assertSet('confirm', null);
    expect($this->unit->fresh()->activeSession)->not->toBeNull()
        ->and($this->customer->fresh()->balance)->toBe(40_000);
});

/**
 * Open Play dari kios: mulai → main → berhenti → tagih dari saldo. Diuji lewat
 * komponen supaya alur yang dipakai pelanggan sungguhan yang diperiksa, bukan
 * cuma aksinya sendiri.
 */
test('Open Play starts and stops from the kiosk, settling from balance', function () {
    $this->unit->unitType->update(['hourly_rate' => 6_000]);

    $component = Livewire::actingAs($this->customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('playChoice', 'open')
        ->call('askPlay')
        ->assertSet('confirm', 'open')
        ->call('startOpenPlay');

    $session = $this->unit->fresh()->activeSession;
    expect($session)->not->toBeNull()
        ->and($session->type)->toBe(SessionType::Open);

    // 30 menit berlalu → Rp 3.000 dari tarif Rp 6.000/jam.
    $session->update(['started_at' => now()->subMinutes(30)]);

    $component->call('stopOpenPlay');

    expect($this->unit->fresh()->activeSession)->toBeNull()
        ->and($this->customer->fresh()->balance)->toBe(47_000);
});

/**
 * KEAMANAN: stopOpenPlay adalah method publik Livewire — kepemilikan WAJIB dicek
 * di server, bukan cuma menyembunyikan tombol. Pelanggan lain (atau anonim) yang
 * membuka /kios/<unit> tak boleh menghentikan & menagih sesi Open Play orang lain.
 */
test('a customer cannot stop another customers Open Play session', function () {
    $this->unit->unitType->update(['hourly_rate' => 6_000]);

    $victimSession = app(StartKioskOpenPlayAction::class)->handle($this->customer->fresh(), $this->unit);
    $victimSession->update(['started_at' => now()->subMinutes(30)]);

    $attacker = Customer::factory()->create(['balance' => 99_000]);

    Livewire::actingAs($attacker, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->call('stopOpenPlay');

    // Sesi korban TETAP berjalan; tak ada yang ditagih (korban maupun penyerang).
    expect($this->unit->fresh()->activeSession)->not->toBeNull()
        ->and($this->customer->fresh()->balance)->toBe(50_000)
        ->and($attacker->fresh()->balance)->toBe(99_000);
});

/**
 * Celah akun-buang ditutup DI UI juga: akun baru bersaldo nol diberi tahu isi
 * saldo dulu, konfirmasi tidak pernah terbuka.
 */
test('a fresh zero-balance account is told to top up before Open Play', function () {
    $broke = Customer::factory()->create(['balance' => 0]);

    Livewire::actingAs($broke, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('playChoice', 'open')
        ->call('askPlay')
        ->assertSet('confirm', null)
        ->assertSet('error', 'Isi saldo dulu sebelum Open Play.');

    expect($this->unit->fresh()->activeSession)->toBeNull();
});
