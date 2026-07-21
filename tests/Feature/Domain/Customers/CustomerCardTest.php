<?php

use App\Domain\Customers\Actions\RegisterCustomerAction;
use App\Domain\Customers\CardNumber;
use App\Models\Customer;
use App\Models\Unit;
use Livewire\Livewire;

/**
 * Nomor kartu dibuat sekali saat akun dibuat, bukan diserahkan ke pemanggil —
 * akun tanpa kartu tidak boleh ada.
 */
test('every new account gets a 16-character uppercase card number', function () {
    $customer = app(RegisterCustomerAction::class)->handle('Budi', '081234567890', '246810');

    expect($customer->card_number)
        ->toHaveLength(16)
        ->toMatch('/^[A-Z0-9]{16}$/')
        ->toBe(mb_strtoupper($customer->card_number));
});

test('two accounts never share a card number', function () {
    $a = Customer::factory()->create();
    $b = Customer::factory()->create();

    expect($a->card_number)->not->toBe($b->card_number);
});

/**
 * Di layar pelanggan hanya empat karakter terakhir yang boleh terlihat — sama
 * seperti kartu sungguhan. Nomor utuh tidak pernah ditampilkan.
 */
test('the card shows only the last four characters', function () {
    $customer = Customer::factory()->create();

    $masked = $customer->maskedCardNumber();

    expect($masked)
        ->toContain(substr($customer->card_number, -4))
        ->not->toContain(substr($customer->card_number, 0, 12));
});

/**
 * Alfabetnya membuang karakter yang mudah tertukar saat dibacakan ke kasir —
 * tidak ada 0/O atau 1/I/L. Ini identitas yang diucapkan manusia, bukan dipindai.
 */
test('the alphabet avoids characters that are easy to mishear', function () {
    $numbers = collect(range(1, 200))->map(fn () => CardNumber::generate())->implode('');

    expect($numbers)->not->toContain('0')
        ->and($numbers)->not->toContain('O')
        ->and($numbers)->not->toContain('1')
        ->and($numbers)->not->toContain('I')
        ->and($numbers)->not->toContain('L');
});

/**
 * Kartu di dasbor menampilkan saldo dan nomor tersamar — dan JANGAN pernah
 * membocorkan nomor utuh ke HTML, karena "tersamar di layar tapi utuh di
 * sumber halaman" bukan tersamar sama sekali.
 */
test('the dashboard card shows the balance and masked number, never the full one', function () {
    $unit = Unit::factory()->create();
    $customer = Customer::factory()->withBalance(35_000)->create(['name' => 'Uji Pelanggan']);

    Livewire::actingAs($customer, 'customer')
        ->test('kiosk.unit-kiosk', ['unit' => $unit])
        ->assertSee('Uji Pelanggan')
        ->assertSee('Rp 35.000')
        ->assertSee($customer->maskedCardNumber())
        ->assertDontSee($customer->card_number);
});
