<?php

use App\Domain\Kiosk\UnitKioskScreen;
use App\Domain\Settings\SettingKey;
use App\Http\Controllers\HealthController;
use App\Models\Setting;
use App\Models\Unit;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Kesehatan sistem. Tanpa autentikasi karena pemanggilnya adalah orkestrator
// dan monitoring yang tidak punya sesi — keduanya sengaja tidak membocorkan
// detail kegagalan internal. Lihat HealthController untuk alasan pemisahannya.
Route::get('/health', [HealthController::class, 'health'])->name('health');
Route::get('/ready', [HealthController::class, 'ready'])->name('ready');

// Aset merek (logo & favicon) disajikan dari storage lewat PHP, bukan oleh
// nginx: container web me-mount public/ dari host read-only, sedangkan
// unggahan tersimpan di volume milik container app — nginx tidak bisa
// melihatnya. Pola yang sama dipakai gambar kios di bawah.
//
// Tanpa login, karena favicon diminta browser sebelum siapa pun masuk.
// isBrandAsset() adalah daftar-putihnya: tanpa itu route ini bisa dipakai
// membaca nilai pengaturan APA PUN sebagai berkas, termasuk nomor rekening.
Route::get('/brand/{key}', function (string $key) {
    $settingKey = SettingKey::tryFrom($key);

    abort_unless($settingKey?->isBrandAsset() ?? false, 404);

    $path = trim((string) Setting::get($settingKey));

    abort_if($path === '' || ! Storage::disk('local')->exists($path), 404);

    $mime = Storage::disk('local')->mimeType($path) ?: '';

    // Daftar-putih tipe. Berkas yang tipenya di luar daftar disajikan sebagai
    // octet-stream — bersama nosniff, browser menolak merendernya sama sekali
    // alih-alih menebak-nebak isinya.
    $safeMime = in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/svg+xml'], true)
        ? $mime
        : 'application/octet-stream';

    return response(Storage::disk('local')->get($path), 200, [
        'Content-Type' => $safeMime,
        // URL sudah mengandung sidik jari isi berkas, jadi aman di-cache lama.
        'Cache-Control' => 'public, max-age=604800',

        // SVG BUKAN sekadar gambar: ia dokumen XML yang boleh memuat <script>,
        // dan route ini menyajikannya tanpa login di ORIGIN YANG SAMA dengan
        // panel admin. Tanpa tiga header di bawah, siapa pun yang bisa
        // mengunggah logo bisa menanam skrip yang berjalan di sesi kasir atau
        // owner yang sedang membuka panel — stored XSS yang berujung
        // pengambilalihan akun, di sistem yang memegang uang.
        //
        // sandbox + default-src 'none' mematikan skrip di dalam dokumen SVG
        // saat URL-nya dibuka langsung; style-src 'unsafe-inline' dipertahankan
        // supaya logo berwarna tetap tampil benar. Dirender lewat <img> skrip
        // memang tidak jalan — tetapi jalur berbahayanya adalah membuka URL itu
        // langsung, dan justru itu yang paling mudah dilakukan.
        'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        'X-Content-Type-Options' => 'nosniff',
        'Content-Disposition' => 'inline; filename="brand"',
    ]);
})->name('brand.asset');

// Root diarahkan ke panel supaya kasir yang mengetik alamat server saja
// (tanpa /admin) tetap sampai ke tempat yang benar.
Route::redirect('/', '/admin');

// Kios pelanggan: SATU-SATUNYA halaman tanpa login di aplikasi ini.
//
// Pelanggan masuk dengan nomor WA (OTP/PIN), lalu main dari SALDO: pilih paket
// (PlayFromWalletAction) atau Open Play (StartKioskOpenPlayAction), isi saldo
// lewat QRIS/transfer (OpenTopUpAction). Yang dijaga bukan sekadar identitasnya
// melainkan uangnya — saldo dipotong di dalam kunci baris, tak ada main tanpa
// bayar.
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
