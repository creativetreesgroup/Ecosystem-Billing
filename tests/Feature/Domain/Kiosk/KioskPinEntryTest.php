<?php

use App\Models\Customer;
use App\Models\Unit;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/**
 * PIN dimasukkan lewat enam kotak angka, sama seperti kode OTP — bukan satu
 * kotak teks panjang. Bentuknya sama; isinya tidak: PIN dipakai berulang dan
 * kios berdiri di ruangan terbuka, jadi angkanya tetap disamarkan.
 */
beforeEach(function () {
    $this->unit = Unit::factory()->create();
    $this->customer = Customer::factory()->create([
        'phone' => '081212345678',
        'pin_hash' => Hash::make('123456'),
        'is_active' => true,
    ]);
});

test('the PIN screen offers six boxes, not a single text field', function () {
    Livewire::test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('phone', '081212345678')
        ->set('step', 'pin')
        ->assertSeeHtml('maxlength="1"')
        ->assertSeeHtml('type="password"')
        // Kotak panjang lama dikenali dari placeholder-nya.
        ->assertDontSeeHtml('placeholder="PIN 6 angka"');
});

/**
 * Setelah PIN ditolak, propertinya HARUS ikut dikosongkan: enam kotak itu
 * bereaksi pada nilai kosong untuk membersihkan dirinya. Tanpa ini pelanggan
 * menatap enam titik yang tak bisa dibaca ulang dan harus menghapusnya satu
 * per satu sebelum boleh mencoba lagi.
 */
test('a rejected PIN is cleared so the boxes empty themselves', function () {
    Livewire::test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('phone', '081212345678')
        ->set('step', 'pin')
        ->set('pin', '000000')
        ->call('signInWithPin')
        ->assertSet('pin', '')
        ->assertSet('step', 'pin');
});

test('the right PIN still signs the customer in', function () {
    Livewire::test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('phone', '081212345678')
        ->set('step', 'pin')
        ->set('pin', '123456')
        ->call('signInWithPin')
        ->assertSet('step', 'phone');

    expect(auth('customer')->id())->toBe($this->customer->id);
});

/** Layar daftar memakai kotak yang sama, dan namanya tetap diberi label. */
test('the sign-up screen uses the same boxes and labels them', function () {
    Livewire::test('kiosk.unit-kiosk', ['unit' => $this->unit])
        ->set('phone', '081299887766')
        ->set('step', 'register')
        ->assertSee('Buat PIN 6 angka')
        ->assertSeeHtml('maxlength="1"')
        ->assertDontSeeHtml('placeholder="Buat PIN 6 angka"');
});
