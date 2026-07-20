<?php

use App\Domain\Kiosk\UnitKioskScreen;
use App\Models\Unit;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

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

// Gambar QR unit. Tanpa login KARENA memang harus bisa diambil oleh TV yang
// menampilkannya lewat Google Cast — TV tidak punya sesi dan tidak akan pernah
// punya. Yang dikandungnya hanya tautan ke halaman kios unit itu, yang juga
// publik; tidak ada apa pun yang rahasia di dalamnya.
Route::get('/kios/{unit:code}/qr.jpg', function (Unit $unit) {
    abort_unless($unit->is_active, 404);

    // Disimpan sebagai BERKAS, bukan di cache database.
    //
    // Menggambarnya makan ~750 ms, jadi menyimpannya jelas perlu. Tapi versi
    // pertama memakai Cache::remember dan gagal: penyimpan cache di sini adalah
    // database, dan JPEG 200 KB mentah membuat query-nya meledak. Gambar memang
    // tempatnya di disk — bukan di kolom teks.
    $path = 'kiosk-screens/'.$unit->code.'-'.$unit->updated_at?->timestamp.'.jpg';

    if (! Storage::disk('local')->exists($path)) {
        Storage::disk('local')->put($path, UnitKioskScreen::jpegFor($unit));
    }

    return response(Storage::disk('local')->get($path), 200, [
        'Content-Type' => 'image/jpeg',
        // Cast mengambil ulang gambarnya tiap kali ditampilkan; tautannya tidak
        // pernah berubah selama kode unitnya tetap.
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->name('kiosk.unit.qr');
