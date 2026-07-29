<?php

use App\Models\Customer;
use App\Models\User;

/**
 * Otorisasi kanal hanya benar-benar dijalankan oleh broadcaster nyata; driver
 * 'null' di env test meloloskan semuanya. Jadi dipasang broadcaster reverb
 * (kompatibel pusher) dengan kredensial dummy supaya callback kanalnya betul
 * dieksekusi — 403 saat ditolak muncul sebelum tanda tangan dibuat.
 *
 * routes/channels.php di-load ulang DI SINI dengan sengaja: di env test
 * BROADCAST_CONNECTION=null saat boot, jadi kanal + opsi guard-nya semula
 * terdaftar di broadcaster null; me-require ulang setelah default jadi reverb
 * mendaftarkan definisi channels.php YANG SEBENARNYA (termasuk guards) pada
 * broadcaster reverb. Di produksi BROADCAST_CONNECTION=reverb sejak boot, jadi
 * langkah ini tidak diperlukan di sana.
 */
beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'testkey',
        'broadcasting.connections.reverb.secret' => 'testsecret',
        'broadcasting.connections.reverb.app_id' => 'testapp',
    ]);

    require base_path('routes/channels.php');
});

/**
 * Tiap pelanggan HANYA kanalnya sendiri — kalau tidak, satu pelanggan bisa
 * menguping dorongan pembayaran pelanggan lain.
 */
test('a customer may authorize their own channel but not another', function () {
    $customer = Customer::factory()->create();
    $other = Customer::factory()->create();

    $this->actingAs($customer, 'customer')
        ->post('/broadcasting/auth', ['channel_name' => 'private-customer.'.$customer->id, 'socket_id' => '1234.5678'])
        ->assertSuccessful();

    $this->actingAs($customer, 'customer')
        ->post('/broadcasting/auth', ['channel_name' => 'private-customer.'.$other->id, 'socket_id' => '1234.5678'])
        ->assertForbidden();
});

/**
 * Pemisahan guard: user panel (guard web) TIDAK boleh membuka kanal pelanggan.
 */
test('a panel user cannot authorize a customer channel', function () {
    $customer = Customer::factory()->create();

    $this->actingAs(User::factory()->create()) // guard web
        ->post('/broadcasting/auth', ['channel_name' => 'private-customer.'.$customer->id, 'socket_id' => '1234.5678'])
        ->assertForbidden();
});
