<?php

use App\Models\Unit;
use Illuminate\Support\Facades\Route;

// Root diarahkan ke panel supaya kasir yang mengetik alamat server saja
// (tanpa /admin) tetap sampai ke tempat yang benar.
Route::redirect('/', '/admin');

// Kios pelanggan: SATU-SATUNYA halaman tanpa login di aplikasi ini.
//
// Tidak ada akun karena tidak perlu: pelanggan berdiri di depan unitnya, dan
// memindai kode fisik di sana sudah membuktikan ia ada di tempat. Yang dijaga
// bukan identitasnya melainkan uangnya — sesi baru berjalan setelah
// pembayaran terbukti (OpenKioskCheckoutAction).
//
// Tetap LAN-only: halaman ini tidak boleh dijangkau dari internet, sama
// seperti seluruh panel (§14, tanpa port forwarding).
Route::get('/kios/{unit:code}', function (Unit $unit) {
    abort_unless($unit->is_active, 404);

    return view('kiosk.show', ['unit' => $unit]);
})->name('kiosk.unit');
