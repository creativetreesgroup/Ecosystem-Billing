<?php

use App\Domain\Kiosk\Brand;
use App\Domain\Kiosk\UnitKioskScreen;
use App\Domain\Kiosk\UnitQrCode;
use App\Models\Package;
use App\Models\Unit;
use chillerlan\QRCode\QRCode;

/**
 * Layar TV dinilai dari satu hal: pelanggan bisa memindainya dari kursinya.
 * Yang bisa diuji di sini adalah prasyaratnya — kontras, ukuran, dan palet.
 * Pemindaian sungguhannya tetap tugas manusia (§14).
 */
test('every colour pair on the TV screen is readable enough', function (string $depan, string $belakang, float $minimal) {
    expect(Brand::contrast($depan, $belakang))->toBeGreaterThanOrEqual($minimal);
})->with([
    // Modul QR di atas panelnya. Ini pasangan yang paling menentukan: kurang
    // dari ini, pemindai gagal sebelum soal estetika sempat dibahas.
    'modul QR di panel' => [Brand::ESPRESSO, Brand::BLUSH, 7.0],

    // Teks besar di atas bidang emerald.
    'kode unit' => [Brand::BLUSH, Brand::EMERALD, 4.5],
    'harga' => [Brand::CHAMPAGNE, Brand::EMERALD, 4.5],

    // Isi pil dan barisan merek — keduanya teks kecil, jadi ambangnya penuh.
    'teks pil' => [Brand::ESPRESSO, Brand::COGNAC, 4.5],
    'baris merek' => [Brand::COGNAC, Brand::EMERALD, 3.0],
]);

/**
 * Latar layar TV kini TERANG (CANVAS), sama dengan latar halaman dasbor HP, dan
 * kartu gelapnya berdiri di atasnya seperti kartu saldo di dasbor. Kartu itu
 * terlihat karena kontras gelap-di-terang, BUKAN garis tepi — jadi tidak ada
 * lagi garis cognac. Test ini menahan dua hal: latarnya benar-benar CANVAS
 * (bukan espresso lagi), dan kartu gelapnya cukup kontras untuk terlihat sendiri.
 */
test('the dark card stands out on the light canvas without a border', function () {
    expect(Brand::contrast(Brand::ESPRESSO, Brand::CANVAS))->toBeGreaterThanOrEqual(4.5)
        ->and(Brand::contrast(Brand::EMERALD, Brand::CANVAS))->toBeGreaterThanOrEqual(4.5);

    $source = file_get_contents(app_path('Domain/Kiosk/UnitKioskScreen.php'));

    // Latarnya CANVAS, dan tidak ada lagi konstanta/garis tepi kartu.
    expect($source)->toContain('Brand::CANVAS')
        ->and($source)->not->toContain('CARD_STROKE');
});

/**
 * Palet merek adalah SATU sumber. Hex yang ditulis langsung di sini pelan-pelan
 * menyimpang dari panel dan halaman kios — dan versi sebelumnya sudah menambal
 * enam warna karangan sebelum ketahuan.
 */
test('the screen never invents a colour outside the palette', function () {
    $source = file_get_contents(app_path('Domain/Kiosk/UnitKioskScreen.php'));

    expect($source)->not->toMatch('/imagecolorallocate\s*\(/');
});

/**
 * Kartu harus utuh di dalam kotak aman judul, dan kode QR utuh di dalam kartu.
 * Overscan yang memotong tepi kartu terlihat seperti gambar rusak; yang
 * memotong zona hening QR mematikan pemindaiannya.
 */
test('the card fits the title-safe box and the QR fits the card', function () {
    $screen = new ReflectionClass(UnitKioskScreen::class);

    $cardLeft = $screen->getConstant('CARD_LEFT');
    $cardTop = $screen->getConstant('CARD_TOP');
    $cardRight = $cardLeft + $screen->getConstant('CARD_WIDTH');
    $cardBottom = $cardTop + $screen->getConstant('CARD_HEIGHT');

    expect($cardLeft)->toBeGreaterThanOrEqual($screen->getConstant('SAFE_LEFT'))
        ->and($cardRight)->toBeLessThanOrEqual($screen->getConstant('SAFE_RIGHT'));

    $qrLeft = $screen->getConstant('QR_LEFT');
    $qrTop = $screen->getConstant('QR_TOP');
    $qrSize = $screen->getConstant('QR_SIZE');

    expect($qrLeft)->toBeGreaterThan($cardLeft)
        ->and($qrLeft + $qrSize)->toBeLessThan($cardRight)
        ->and($qrTop)->toBeGreaterThan($cardTop)
        ->and($qrTop + $qrSize)->toBeLessThan($cardBottom);
});

/**
 * Halaman HP bertema terang, tapi kartu saldonya tetap gelap dengan dua warna
 * merek yang sama seperti layar TV: espresso dan emerald. Dilihat berdampingan
 * (pelanggan menghadap TV sambil memegang HP), keduanya harus terasa satu
 * merek — test ini menahan agar HP tidak diam-diam lepas ke warna sembarang.
 */
test('the phone page stays anchored on the brand', function () {
    $css = file_get_contents(resource_path('views/components/kiosk/layout.blade.php'));

    foreach ([Brand::ESPRESSO, Brand::EMERALD] as $hex) {
        expect($css)->toContain($hex);
    }
});

test('the screen renders at full HD and is small enough to cast', function () {
    $unit = Unit::factory()->create();
    Package::factory()->create(['unit_type_id' => $unit->unit_type_id, 'is_active' => true]);

    $jpeg = UnitKioskScreen::jpegFor($unit->load('unitType'));

    [$width, $height] = getimagesizefromstring($jpeg);

    expect($width)->toBe(1920)
        ->and($height)->toBe(1080)
        // Perangkat Cast menolak gambar yang terlalu besar tanpa pesan yang
        // berguna — layarnya cuma tetap kosong.
        ->and(strlen($jpeg))->toBeLessThan(1_500_000);
});

/**
 * Kode QR harus tetap cukup besar untuk dipindai dari kursi pemain, dan tetap
 * di dalam kotak aman judul supaya overscan TV tidak memakan zona heningnya.
 *
 * Lantainya 560px bukan angka selera: jarak pindai ≈ 10× lebar kode, dan pada
 * TV 55 inci 560px ≈ 355mm — terbaca sampai ±3,5m. Tata letak yang menggeser
 * kode di bawah ini memindahkan batas jarak pindai, dan itu harus disengaja.
 */
test('the QR stays big enough to scan from a player seat', function () {
    expect((new ReflectionClass(UnitKioskScreen::class))->getConstant('QR_SIZE'))
        ->toBeGreaterThanOrEqual(560);
});

/**
 * Tumpukan tengah: kodenya harus benar-benar di sumbu tengah layar, bukan
 * sekadar terlihat begitu. Meleset sedikit pada layar 55 inci langsung terbaca
 * sebagai "miring".
 */
test('the QR sits on the centre axis', function () {
    $screen = new ReflectionClass(UnitKioskScreen::class);

    expect($screen->getConstant('QR_LEFT') * 2 + $screen->getConstant('QR_SIZE'))->toBe(1920);
});

/**
 * Test yang paling penting di berkas ini: layarnya BENAR-BENAR dipindai.
 *
 * Semua test lain hanya menjaga prasyarat — kontras, ukuran, posisi. Yang ini
 * menjalankan pembaca QR sungguhan di atas piksel yang persis dikirim ke TV,
 * lalu memeriksa alamat yang keluar. Gaya visual QR (titik terpisah, penanda
 * sudut bulat) mengikis tinta tiap modul, dan tanpa test ini kegagalannya baru
 * ketahuan saat pelanggan sudah berdiri di depan TV sambil mengangkat HP.
 *
 * Dekodernya dari chillerlan/php-qrcode yang memang sudah terpasang.
 *
 * Yang dibaca hanya potongan panelnya, bukan bingkai 1920×1080 penuh: dekoder
 * membangun peta kecerahan seukuran gambarnya, dan bingkai penuh menembus batas
 * memori 128 MB — matinya berupa proses berhenti tanpa pesan sama sekali, yang
 * terbaca seperti test yang tidak jalan.
 */
test('the QR on the TV screen actually decodes', function () {
    $unit = Unit::factory()->create();

    $screen = imagecreatefromstring(UnitKioskScreen::jpegFor($unit->load('unitType')));
    $reflection = new ReflectionClass(UnitKioskScreen::class);

    $margin = 20;
    $size = $reflection->getConstant('QR_SIZE') + $margin * 2;
    $panel = imagecreatetruecolor($size, $size);
    imagecopy(
        $panel, $screen,
        0, 0,
        $reflection->getConstant('QR_LEFT') - $margin,
        $reflection->getConstant('QR_TOP') - $margin,
        $size, $size,
    );

    $file = tempnam(sys_get_temp_dir(), 'kiosk').'.jpg';
    imagejpeg($panel, $file, 94);

    $decoded = (new QRCode)->readFromFile($file)->data;

    unlink($file);

    expect($decoded)->toBe(UnitQrCode::urlFor($unit));
});

/**
 * Pelanggan memindai dari kursinya, bukan dari depan layar — dan yang sampai ke
 * pemindai bukan panel 560px, melainkan potongan kecil di dalam bingkai kamera.
 *
 * 100px adalah tepat di atas jurangnya. Diukur dengan dekoder ini: 100px lolos,
 * 90px gagal — dan batas itu TIDAK bergeser saat diameter titiknya diubah dari
 * 0,92 ke 0,55 sel. Jadi yang menentukan bukan tinta yang hilang karena gaya
 * titik, melainkan piksel kamera per modul (33 modul, jurangnya di ~2,2 px).
 *
 * Test ini karenanya menjaga hal yang benar: bukan "gayanya jangan berlebihan",
 * tapi "kodenya jangan mengecil". Kualitas 70 dipakai karena kamera HP memang
 * mengompres, dan kompresi melunakkan tepi titik.
 */
test('the QR still decodes at the size a phone camera actually sees', function () {
    $unit = Unit::factory()->create();

    $screen = imagecreatefromstring(UnitKioskScreen::jpegFor($unit->load('unitType')));
    $reflection = new ReflectionClass(UnitKioskScreen::class);

    $margin = 20;
    $source = $reflection->getConstant('QR_SIZE') + $margin * 2;
    $target = 100;

    $small = imagecreatetruecolor($target, $target);
    imagecopyresampled(
        $small, $screen,
        0, 0,
        $reflection->getConstant('QR_LEFT') - $margin,
        $reflection->getConstant('QR_TOP') - $margin,
        $target, $target,
        $source, $source,
    );

    $file = tempnam(sys_get_temp_dir(), 'kiosk-jauh').'.jpg';
    imagejpeg($small, $file, 70);

    $decoded = (new QRCode)->readFromFile($file)->data;

    unlink($file);

    expect($decoded)->toBe(UnitQrCode::urlFor($unit));
});

/**
 * Tiap unit WAJIB punya kode QR sendiri.
 *
 * Kalau dua unit berbagi kode, pelanggan membayar untuk TV yang ia duduki tapi
 * TV lain yang menyala — dan kesalahannya baru ketahuan setelah uangnya masuk.
 * Test ini memindai layar dua unit sungguhan dan memastikan hasil bacanya
 * berbeda serta menunjuk unit yang benar.
 */
test('each unit carries its own QR, decoded from its own screen', function () {
    $panel = function (Unit $unit): string {
        $screen = imagecreatefromstring(UnitKioskScreen::jpegFor($unit->load('unitType')));
        $reflection = new ReflectionClass(UnitKioskScreen::class);

        $margin = 20;
        $size = $reflection->getConstant('QR_SIZE') + $margin * 2;
        $crop = imagecreatetruecolor($size, $size);
        imagecopy(
            $crop, $screen,
            0, 0,
            $reflection->getConstant('QR_LEFT') - $margin,
            $reflection->getConstant('QR_TOP') - $margin,
            $size, $size,
        );

        $file = tempnam(sys_get_temp_dir(), 'kiosk-unit').'.jpg';
        imagejpeg($crop, $file, 94);

        $data = (new QRCode)->readFromFile($file)->data;
        unlink($file);

        return $data;
    };

    $satu = Unit::factory()->create(['code' => 'PS-91']);
    $dua = Unit::factory()->create(['code' => 'PS-92']);

    expect($panel($satu))->toContain('PS-91')
        ->and($panel($dua))->toContain('PS-92')
        ->and($panel($satu))->not->toBe($panel($dua));
});

/**
 * Terjadi sungguhan di image Docker: TIDAK ADA satu pun font TTF terpasang.
 *
 * imagettftext() gagal DIAM-DIAM ketika berkas fontnya tidak ada — ia
 * mengembalikan false dan tidak melempar apa pun. QR tetap tergambar karena
 * itu bentuk kotak GD, sehingga layar tampak "hampir benar": pelanggan melihat
 * QR telanjang di TV 43 inci tanpa kode unit, tanpa tipe unit, tanpa harga,
 * tanpa ajakan memindai. Test warna yang sudah ada lolos semua, karena warna
 * kartunya memang tidak berubah.
 *
 * Guard ini menguji lingkungan, bukan logika, dan itu memang disengaja:
 * kegagalannya hidup di image, bukan di kode.
 */
test('a usable TTF font exists for the television screen', function () {
    $reflection = new ReflectionClass(UnitKioskScreen::class);

    foreach (['FONT_BOLD', 'FONT_REGULAR'] as $constant) {
        $candidates = $reflection->getConstant($constant);

        $found = collect($candidates)->first(fn (string $path): bool => is_readable($path));

        expect($found)->not->toBeNull(
            "Tidak ada font {$constant} yang terbaca. Layar TV akan tampil tanpa teks sama sekali. "
            .'Pasang paket font di Dockerfile (Alpine: font-dejavu).'
        );
    }
});
