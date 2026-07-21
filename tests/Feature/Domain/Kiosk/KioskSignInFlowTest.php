<?php

use App\Domain\Customers\Actions\RegisterCustomerAction;
use App\Domain\Customers\Otp\OtpChannel;
use App\Models\Customer;
use App\Models\Unit;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/**
 * Alur masuk kios: nomor dulu → OTP (atau daftar), dengan PIN sebagai cadangan.
 *
 * Diuji dari komponennya, bukan lewat browser: penataan piksel bisa dilihat
 * mata, tapi "kode yang benar membuat pelanggan masuk, kode yang salah tidak"
 * adalah jalur uang — kalau ini meleset, orang bisa masuk ke akun yang bukan
 * miliknya, dan itu tidak boleh cuma diperiksa sekali dengan tangan.
 */
function kioskOtpSpy(): object
{
    $channel = new class implements OtpChannel
    {
        public array $sent = [];

        public function send(string $phone, string $code): bool
        {
            $this->sent[] = ['phone' => $phone, 'code' => $code];

            return true;
        }

        public function name(): string
        {
            return 'uji';
        }

        public function isConfigured(): bool
        {
            return true;
        }
    };

    app()->instance(OtpChannel::class, $channel);

    return $channel;
}

beforeEach(function () {
    $this->unit = Unit::factory()->create();
    $this->channel = kioskOtpSpy();
    RateLimiter::clear('otp-request:081234567890');
    RateLimiter::clear('kiosk-pin:081234567890');
});

test('a registered number is sent an OTP, and the right code signs in', function () {
    app(RegisterCustomerAction::class)->handle('Budi', '081234567890', '246810');

    $component = Livewire::test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('phone', '081234567890')
        ->call('continueWithPhone')
        ->assertSet('step', 'otp');

    expect($this->channel->sent)->toHaveCount(1)
        ->and($this->channel->sent[0]['phone'])->toBe('081234567890');

    $component->set('code', $this->channel->sent[0]['code'])
        ->call('verifyOtp');

    expect(Auth::guard('customer')->check())->toBeTrue()
        ->and(Auth::guard('customer')->user()->name)->toBe('Budi');
});

test('a wrong OTP does not sign anyone in', function () {
    app(RegisterCustomerAction::class)->handle('Budi', '081234567890', '246810');

    Livewire::test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('phone', '081234567890')
        ->call('continueWithPhone')
        ->set('code', '000000')
        ->call('verifyOtp');

    expect(Auth::guard('customer')->check())->toBeFalse();
});

/**
 * Cadangan yang membuat orang tidak pernah terkunci hanya karena WhatsApp-nya
 * telat: PIN pendaftaran tetap sah dari layar OTP.
 */
test('the PIN still signs in when the OTP never arrives', function () {
    app(RegisterCustomerAction::class)->handle('Budi', '081234567890', '246810');

    Livewire::test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('phone', '081234567890')
        ->call('continueWithPhone')
        ->call('usePin')
        ->assertSet('step', 'pin')
        ->set('pin', '246810')
        ->call('signInWithPin');

    expect(Auth::guard('customer')->check())->toBeTrue();
});

/**
 * Nomor yang belum punya akun diarahkan mendaftar — BUKAN dikirimi OTP ke akun
 * yang tidak ada. Membedakan keduanya di langkah nomor itulah yang membuat
 * alurnya tetap satu kolom.
 */
test('an unknown number is routed to registration, no OTP sent', function () {
    Livewire::test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('phone', '081234567890')
        ->call('continueWithPhone')
        ->assertSet('step', 'register');

    expect($this->channel->sent)->toBeEmpty();
});

test('registering from the kiosk creates the account and signs in', function () {
    Livewire::test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('phone', '081234567890')
        ->call('continueWithPhone')
        ->set('name', 'Sari')
        ->set('pin', '246810')
        ->call('register');

    expect(Auth::guard('customer')->check())->toBeTrue()
        ->and(Auth::guard('customer')->user()->name)->toBe('Sari');
});

test('a malformed number is refused before any OTP is sent', function () {
    Livewire::test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('phone', 'bukan-nomor')
        ->call('continueWithPhone')
        ->assertSet('step', 'phone')
        ->assertSet('error', 'Nomor WhatsApp tidak dikenali. Contoh: 081234567890');

    expect($this->channel->sent)->toBeEmpty();
});

/**
 * Menu cepat dasbor: tiap tile menampilkan bagiannya dan menyembunyikan yang
 * lain. Distruktur pakai @if/@elseif/@else, jadi patut dijaga agar tidak ada
 * tab yang diam-diam menampilkan dua bagian sekaligus atau kosong.
 */
test('the dashboard tabs swap sections without leaving one blank', function () {
    $customer = Customer::factory()->withBalance(50_000)->create();

    $component = Livewire::actingAs($customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit]);

    // Default: tab Main → pilih paket terlihat, riwayat tidak.
    $component->assertSet('tab', 'main')
        ->assertSee('Open Play')
        ->assertDontSee('Belum ada transaksi');

    $component->call('$set', 'tab', 'history')
        ->assertSee('Transaksi')
        ->assertDontSee('Open Play');

    $component->call('$set', 'tab', 'topup')
        ->assertSee('Isi saldo')
        ->assertDontSee('Open Play');
});

/**
 * Riwayat dipaginasi, dengan "tampilkan berapa" yang diketik manual. Dua hal
 * yang patut dijaga karena mudah rusak diam-diam: angka ekstrem harus DIJEPIT
 * (0 atau kosong tidak boleh jadi kueri kosong), dan mengubah jumlah per
 * halaman harus MENGEMBALIKAN ke halaman 1 — kalau tidak, pelanggan bisa
 * terdampar di halaman yang sudah tidak ada dan melihat daftar kosong.
 */
test('history paginates, with a manual per-page that clamps and resets the page', function () {
    $customer = Customer::factory()->create();
    WalletTransaction::factory()->count(12)->for($customer)->create();

    $component = Livewire::actingAs($customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('tab', 'history');

    // Default 5 per halaman → 12 baris = 3 halaman.
    $component->assertSee('Hal 1 / 3');

    // Pindah ke halaman 2, lalu perbesar per-halaman → balik ke halaman 1.
    $component->call('gotoPage', 2)->assertSee('Hal 2 / 3');
    $component->set('perPage', 10)->assertSee('Hal 1 / 2');

    // Angka ekstrem dijepit: 0 → 1 per halaman → 12 halaman, bukan kueri kosong.
    $component->set('perPage', 0)->assertSee('Hal 1 / 12');
});
