<?php

use Illuminate\Support\Facades\DB;

/**
 * Endpoint kesehatan adalah kontrak dengan orkestrator dan monitoring, bukan
 * halaman biasa. Yang diuji di sini bukan "apakah mengembalikan 200", tetapi
 * tiga janji yang kalau dilanggar merugikan operasional outlet:
 *
 *  1. /health tidak boleh menyentuh dependensi — kalau ikut mengecek database,
 *     MySQL yang restart akan memicu orkestrator membunuh aplikasi yang sehat.
 *  2. /ready harus menjawab 503, bukan 500, saat dependensi jatuh.
 *  3. Keduanya tidak boleh membocorkan detail internal ke pemanggil anonim.
 */
test('health responds ok without touching the database', function () {
    // Kalau /health suatu hari menyentuh database, ekspektasi ini yang gagal
    // lebih dulu — sebelum restart loop terjadi di outlet.
    DB::shouldReceive('connection')->never();

    $this->getJson('/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonStructure(['status', 'timestamp']);
});

test('ready reports every dependency when all of them answer', function () {
    $this->getJson('/ready')
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('checks.database', true)
        ->assertJsonPath('checks.redis', true)
        ->assertJsonPath('checks.storage', true);
});

test('ready answers 503 instead of 500 when a dependency is down', function () {
    // 503 adalah sinyal "keluarkan saya dari rotasi"; 500 terbaca sebagai bug
    // aplikasi dan menghasilkan halaman error, bukan keputusan orkestrasi.
    DB::shouldReceive('connection->getPdo')
        ->andThrow(new RuntimeException('koneksi ditolak'));

    $this->getJson('/ready')
        ->assertStatus(503)
        ->assertJsonPath('status', 'not_ready')
        ->assertJsonPath('checks.database', false);
});

test('a failing dependency never leaks its error message to the caller', function () {
    DB::shouldReceive('connection->getPdo')
        ->andThrow(new RuntimeException('SQLSTATE[HY000] host=10.0.0.5 user=ctb_app password=rahasia'));

    $body = $this->getJson('/ready')->assertStatus(503)->content();

    expect($body)
        ->not->toContain('rahasia')
        ->not->toContain('10.0.0.5')
        ->not->toContain('SQLSTATE')
        ->not->toContain('ctb_app');
});

test('health endpoints are reachable without authentication', function () {
    // Orkestrator dan Prometheus tidak punya sesi. Kalau suatu hari middleware
    // auth merambat ke seluruh route, test ini memberi tahu lebih dulu —
    // sebelum outlet berhenti terpantau tanpa ada yang sadar.
    $this->assertGuest();

    $this->getJson('/health')->assertOk();
    $this->getJson('/ready')->assertOk();
});
