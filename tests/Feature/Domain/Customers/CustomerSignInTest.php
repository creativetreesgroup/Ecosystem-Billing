<?php

use App\Domain\Customers\Actions\AuthenticateCustomerAction;
use App\Domain\Customers\Actions\RegisterCustomerAction;
use App\Domain\Customers\CustomerPhone;
use App\Domain\Customers\Exceptions\TooManyPinAttemptsException;
use App\Models\Customer;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    RateLimiter::clear('kiosk-pin:081234567890');
});

/**
 * Orang yang sama menuliskan nomornya berbeda tiap kali. Tanpa penyeragaman,
 * satu orang bisa punya beberapa akun dengan saldo terpisah — dan yang
 * menemukannya adalah pelanggan yang saldonya "hilang".
 */
test('the same phone number written any way finds the same account', function (string $ditulis) {
    expect(CustomerPhone::normalise($ditulis))->toBe('081234567890');
})->with([
    'biasa' => ['081234567890'],
    'kode negara' => ['6281234567890'],
    'dengan plus' => ['+62 812-3456-7890'],
    'berspasi' => ['0812 3456 7890'],
]);

test('something that is not a phone number is refused', function (string $bukan) {
    expect(CustomerPhone::normalise($bukan))->toBeNull();
})->with([
    'terlalu pendek' => ['0812345'],
    'bukan seluler' => ['0211234567'],
    'huruf' => ['nomor-saya'],
]);

test('registering creates an account with an empty wallet', function () {
    $customer = app(RegisterCustomerAction::class)->handle('Budi', '+62 812-3456-7890', '246810');

    expect($customer->phone)->toBe('081234567890')
        ->and($customer->balance)->toBe(0)
        ->and($customer->verifyPin('246810'))->toBeTrue();
});

/**
 * PIN yang seluruhnya sama atau berurutan adalah tebakan pertama siapa pun,
 * dan akun ini memegang saldo sungguhan.
 */
test('a PIN anyone would guess first is refused', function (string $pin) {
    expect(fn () => app(RegisterCustomerAction::class)->handle('Budi', '081234567890', $pin))
        ->toThrow(ValidationException::class);
})->with([
    'seragam' => ['111111'],
    'berurutan' => ['123456'],
    'mundur' => ['654321'],
    'terlalu pendek' => ['1234'],
]);

test('one phone number cannot hold two accounts', function () {
    app(RegisterCustomerAction::class)->handle('Budi', '081234567890', '246810');

    expect(fn () => app(RegisterCustomerAction::class)->handle('Budi Lain', '+6281234567890', '135790'))
        ->toThrow(ValidationException::class);
});

test('signing in with the right PIN works and is remembered', function () {
    app(RegisterCustomerAction::class)->handle('Budi', '081234567890', '246810');

    $customer = app(AuthenticateCustomerAction::class)->handle('0812 3456 7890', '246810');

    expect($customer->name)->toBe('Budi')
        ->and($customer->last_seen_at)->not->toBeNull();
});

/**
 * Enam angka berarti sejuta kemungkinan — habis ditebak dalam hitungan menit
 * oleh skrip mana pun kalau percobaannya tidak dibatasi. Pembatas ini bagian
 * dari alasan PIN pendek boleh dipakai sama sekali.
 */
test('guessing the PIN is locked out after a handful of tries', function () {
    app(RegisterCustomerAction::class)->handle('Budi', '081234567890', '246810');

    foreach (range(1, 5) as $percobaan) {
        try {
            app(AuthenticateCustomerAction::class)->handle('081234567890', '000000');
        } catch (ValidationException) {
            // salah PIN, memang diharapkan
        }
    }

    // PIN yang BENAR pun ditolak selama terkunci — kalau tidak, penebak
    // tinggal menunggu tebakan keenamnya berhasil.
    expect(fn () => app(AuthenticateCustomerAction::class)->handle('081234567890', '246810'))
        ->toThrow(TooManyPinAttemptsException::class);
});

/**
 * Pesan berbeda antara "nomor tidak terdaftar" dan "PIN salah" memberi tahu
 * penebak nomor mana yang punya akun — separuh pekerjaannya selesai sebelum ia
 * menebak satu PIN pun.
 */
test('an unknown number and a wrong PIN are indistinguishable', function () {
    app(RegisterCustomerAction::class)->handle('Budi', '081234567890', '246810');

    $pesan = [];

    foreach ([['081234567890', '999999'], ['081999999999', '246810']] as [$phone, $pin]) {
        try {
            app(AuthenticateCustomerAction::class)->handle($phone, $pin);
        } catch (ValidationException $exception) {
            $pesan[] = $exception->errors();
        }
    }

    expect($pesan[0])->toBe($pesan[1]);
});

test('a deactivated account cannot sign in', function () {
    $customer = app(RegisterCustomerAction::class)->handle('Budi', '081234567890', '246810');
    $customer->update(['is_active' => false]);

    expect(fn () => app(AuthenticateCustomerAction::class)->handle('081234567890', '246810'))
        ->toThrow(ValidationException::class);
});

test('a successful sign-in clears the failed attempts', function () {
    app(RegisterCustomerAction::class)->handle('Budi', '081234567890', '246810');

    try {
        app(AuthenticateCustomerAction::class)->handle('081234567890', '000000');
    } catch (ValidationException) {
        //
    }

    app(AuthenticateCustomerAction::class)->handle('081234567890', '246810');

    expect(RateLimiter::attempts('kiosk-pin:081234567890'))->toBe(0);
});

test('a customer is never confused with a panel user', function () {
    $customer = Customer::factory()->create();

    expect($customer)->toBeInstanceOf(Customer::class)
        ->and(config('auth.guards.customer.provider'))->toBe('customers')
        ->and(config('auth.providers.customers.model'))->toBe(Customer::class);
});
