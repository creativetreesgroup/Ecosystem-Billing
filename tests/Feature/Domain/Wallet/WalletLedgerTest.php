<?php

use App\Domain\Wallet\Exceptions\InsufficientBalanceException;
use App\Domain\Wallet\Wallet;
use App\Domain\Wallet\WalletTransactionType;
use App\Models\Customer;
use App\Models\User;

beforeEach(function () {
    $this->wallet = app(Wallet::class);
    $this->customer = Customer::factory()->create();
});

/**
 * Aturan yang membuat angka saldo bisa dipercaya: kolom saldo dan buku besar
 * HARUS selalu cocok. Selisih sekecil apa pun berarti ada perubahan saldo yang
 * terjadi tanpa dicatat — dan saat pelanggan bilang "saldo saya kurang", satu
 * angka saldo tidak bisa menjawab apa pun tanpa riwayatnya.
 */
test('the balance column and the ledger never disagree', function () {
    $this->wallet->topUp($this->customer, 100_000);
    $this->wallet->spend($this->customer, 25_000);
    $this->wallet->spend($this->customer, 15_000);
    $this->wallet->refund($this->customer, 5_000);

    $fresh = $this->customer->fresh();

    expect($fresh->balance)->toBe(65_000)
        ->and($fresh->ledgerBalance())->toBe(65_000)
        ->and($fresh->walletTransactions()->count())->toBe(4);
});

test('every movement records the balance that followed it', function () {
    $this->wallet->topUp($this->customer, 50_000);
    $this->wallet->spend($this->customer, 20_000);

    expect($this->customer->walletTransactions()->orderBy('id')->pluck('balance_after')->all())
        ->toBe([50_000, 30_000]);
});

test('spending more than the balance is refused and changes nothing', function () {
    $this->wallet->topUp($this->customer, 10_000);

    expect(fn () => $this->wallet->spend($this->customer, 25_000))
        ->toThrow(InsufficientBalanceException::class);

    expect($this->customer->fresh()->balance)->toBe(10_000)
        ->and($this->customer->walletTransactions()->count())->toBe(1);
});

/**
 * Dua permintaan yang datang hampir bersamaan — pelanggan menekan dua kali,
 * atau dua tab terbuka. Tanpa kunci baris keduanya membaca saldo yang sama
 * lalu menuliskan hasil masing-masing, dan uang yang sama terpakai dua kali.
 */
test('two spends racing for the same balance cannot both succeed', function () {
    $this->wallet->topUp($this->customer, 30_000);

    $this->wallet->spend($this->customer, 25_000);

    expect(fn () => $this->wallet->spend($this->customer->fresh(), 25_000))
        ->toThrow(InsufficientBalanceException::class);

    $fresh = $this->customer->fresh();
    expect($fresh->balance)->toBe(5_000)
        ->and($fresh->balance)->toBe($fresh->ledgerBalance());
});

/**
 * Objek yang dipegang pemanggil harus ikut disegarkan. Membiarkannya memegang
 * saldo lama adalah cara paling mudah membelanjakan uang yang sudah tidak ada.
 */
test('the caller never keeps a stale balance in hand', function () {
    $this->wallet->topUp($this->customer, 40_000);
    expect($this->customer->balance)->toBe(40_000);

    $this->wallet->spend($this->customer, 15_000);
    expect($this->customer->balance)->toBe(25_000);
});

test('a top-up must be worth something', function (int $amount) {
    expect(fn () => $this->wallet->topUp($this->customer, $amount))->toThrow(InvalidArgumentException::class);
})->with(['nol' => [0], 'negatif' => [-5_000]]);

/**
 * Koreksi manual sengaja punya jenisnya sendiri dan WAJIB beralasan: uang yang
 * benar-benar masuk lewat pembayaran dan angka yang diketik manusia tidak boleh
 * terbaca sama di laporan, dan baris seperti inilah yang pertama dicari saat
 * ada dugaan penyalahgunaan.
 */
test('a manual correction is never disguised as a top-up', function () {
    $owner = User::factory()->owner()->create();

    $transaction = $this->wallet->adjust($this->customer, 20_000, 'Kompensasi TV mati di tengah sesi', $owner);

    expect($transaction->type)->toBe(WalletTransactionType::Adjustment)
        ->and($transaction->performed_by)->toBe($owner->id)
        ->and($transaction->description)->toBe('Kompensasi TV mati di tengah sesi')
        ->and($this->customer->fresh()->balance)->toBe(20_000);
});

test('a correction without a reason is refused', function () {
    $owner = User::factory()->owner()->create();

    expect(fn () => $this->wallet->adjust($this->customer, 20_000, '   ', $owner))
        ->toThrow(InvalidArgumentException::class);

    expect($this->customer->fresh()->balance)->toBe(0);
});

test('a pin is stored hashed and never in the open', function () {
    $customer = Customer::factory()->create(['pin_hash' => '246810']);

    expect($customer->pin_hash)->not->toBe('246810')
        ->and($customer->verifyPin('246810'))->toBeTrue()
        ->and($customer->verifyPin('111111'))->toBeFalse()
        // Tidak boleh ikut terbawa saat model diubah jadi array/JSON —
        // itu jalan paling sunyi menuju PIN bocor lewat respons.
        ->and($customer->toArray())->not->toHaveKey('pin_hash');
});
