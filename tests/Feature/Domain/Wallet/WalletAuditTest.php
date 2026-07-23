<?php

use App\Domain\Wallet\Wallet;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Rekonsiliasi ala bank: saldo yang dipakai layar (`customers.balance`) harus
 * SELALU sama dengan jumlah buku besarnya. Secara struktur keduanya ditulis
 * bersama di bawah satu kunci, jadi selisih seharusnya mustahil — dan justru
 * itulah alasannya diperiksa. Yang "mustahil" dan tidak pernah diperiksa adalah
 * cara paling umum uang menghilang tanpa ada yang sadar.
 *
 * Perintah ini MELAPOR, bukan membetulkan diam-diam: selisih berarti ada bug
 * atau tangan yang menulis langsung ke tabel, dan menutupinya menghapus satu-
 * satunya jejak.
 */
test('clean books pass the audit', function () {
    $customer = Customer::factory()->create();
    app(Wallet::class)->topUp($customer, 50_000);

    $this->artisan('wallet:audit')
        ->expectsOutputToContain('cocok')
        ->assertSuccessful();
});

test('the audit fails loudly when a balance drifted from its ledger', function () {
    $customer = Customer::factory()->create();
    app(Wallet::class)->topUp($customer, 50_000);

    // Menulis langsung ke kolom, melewati Wallet — persis bentuk kerusakan
    // yang harus ketahuan.
    DB::table('customers')->where('id', $customer->id)->update(['balance' => 75_000]);

    $this->artisan('wallet:audit')
        ->expectsOutputToContain($customer->name)
        ->assertFailed();
});

/** Pelanggan yang belum pernah bertransaksi punya saldo nol dan buku kosong — cocok. */
test('a customer who never transacted is not reported as a mismatch', function () {
    Customer::factory()->create();

    $this->artisan('wallet:audit')->assertSuccessful();
});
