<?php

namespace App\Domain\Kiosk;

use App\Domain\Billing\Rupiah;
use App\Models\Package;
use App\Models\Unit;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use GdImage;

/**
 * Layar yang ditampilkan DI TV saat unit menganggur.
 *
 * Digambar utuh sebagai satu gambar, bukan halaman web, karena Google Cast
 * hanya bisa menampilkan gambar diam. Halaman web butuh receiver Cast terdaftar
 * Google beserta HTTPS publik, yang bentrok dengan panel LAN-only (§14).
 * Konsekuensinya: tidak ada animasi, tidak ada CSS — semuanya bentuk terisi GD.
 *
 * Tata letaknya cerminan dasbor HP: latar TERANG (Brand::CANVAS, sama persis
 * dengan latar halaman dasbor) dengan satu KARTU GELAP gradasi espresso→emerald
 * di tengah — kartu yang sama seperti kartu saldo di dasbor. TV dan HP jadi
 * terasa satu permukaan.
 *
 * Tanpa garis tepi: dulu kartu gelap berdiri di atas latar gelap (emerald di
 * espresso, 1,42:1) sehingga butuh garis cognac agar tepinya terlihat. Di latar
 * terang, kartu gelapnya kontras sendiri (espresso/emerald di CANVAS > 4,5:1) —
 * garisnya tidak lagi diperlukan. Gradasi digambar kecil lalu diperbesar
 * sehingga tidak berpita (lihat gradientCard).
 *
 * Setiap pasangan warnanya diuji di KioskScreenTest.
 */
final class UnitKioskScreen
{
    private const WIDTH = 1920;

    private const HEIGHT = 1080;

    /**
     * Digambar sebesar ini lalu diperkecil. GD tidak punya antialias untuk
     * bentuk terisi — imageantialias() hanya berlaku untuk garis — jadi
     * supersampling satu-satunya cara mendapat tepi halus, dan gerigi pada layar
     * 55 inci terlihat jelas dari kursi pelanggan.
     */
    private const SCALE = 2;

    /**
     * Kotak aman judul: sisi 5%. Sebagian TV masih memotong tepi siaran
     * (overscan), dan potongan yang memakan zona hening QR mematikan
     * pemindaiannya sama sekali — bukan sekadar merusak tampilan.
     */
    private const SAFE_LEFT = 96;

    private const SAFE_RIGHT = 1824;

    private const SAFE_TOP = 54;

    private const SAFE_BOTTOM = 1026;

    private const CARD_LEFT = 520;

    private const CARD_TOP = 50;

    private const CARD_WIDTH = 880;

    private const CARD_HEIGHT = 980;

    /**
     * Patokan lapangan untuk kode QR: jarak pindai maksimal ≈ 10× lebar kodenya.
     *
     * Pada TV 55 inci, lebar layarnya 1218 mm, jadi 1 px ≈ 0,634 mm dan 560 px
     * ≈ 355 mm — terbaca sampai ±3,5 m. Kursi pemain biasanya 2-3 m dari layar,
     * jadi masih bersisa, tapi bantalannya tipis: kartu selebar 880px tidak
     * menyisakan lebih. Angka ini yang menjadi lantai di KioskScreenTest —
     * mengecilkannya demi tata letak berarti memindahkan batas jarak pindai, dan
     * itu keputusan yang harus disengaja, bukan efek samping.
     */
    private const QR_SIZE = 560;

    private const QR_TOP = 300;

    private const QR_LEFT = 680;

    /**
     * imagettftext() menerima POIN pada 96dpi, BUKAN piksel.
     *
     * Terukur di mesin ini: rasio tinggi kapital terhadap $size adalah 0,950 di
     * semua ukuran yang dicoba. Jadi ukuran piksel yang dimaksud desainer harus
     * dikalikan 0,75 sebelum diserahkan ke GD — memasukkan angka pikselnya
     * mentah-mentah menghasilkan teks sepertiga kebesaran.
     */
    private const POINTS_PER_PIXEL = 0.75;

    private const FONT_BOLD = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
        '/Library/Fonts/Arial Bold.ttf',
    ];

    private const FONT_REGULAR = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        '/System/Library/Fonts/Supplemental/Arial.ttf',
        '/Library/Fonts/Arial.ttf',
    ];

    public static function jpegFor(Unit $unit): string
    {
        $big = self::draw($unit);

        $final = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagecopyresampled(
            $final, $big,
            0, 0, 0, 0,
            self::WIDTH, self::HEIGHT,
            self::WIDTH * self::SCALE, self::HEIGHT * self::SCALE,
        );

        // Kanvas 2× dilepas SEBELUM JPEG dibuat. Ia memakan ~33 MB, dan
        // menahannya sampai selesai membuat batas memori 128 MB terlampaui
        // begitu hasilnya ikut disimpan — gagalnya berupa proses mati tanpa
        // pesan, yang jauh lebih sulit dilacak daripada exception.
        unset($big);

        ob_start();
        imagejpeg($final, quality: 94);
        $jpeg = (string) ob_get_clean();

        unset($final);

        return $jpeg;
    }

    private static function draw(Unit $unit): GdImage
    {
        $s = self::SCALE;
        $image = imagecreatetruecolor(self::WIDTH * $s, self::HEIGHT * $s);

        // Setiap warna lewat Brand — tidak ada satu pun hex yang diturunkan di
        // sini. Versi sebelumnya menambal enam warna karangan di luar palet,
        // dan warna karangan itulah yang membuat layarnya tidak lagi terlihat
        // seperti merek yang sama dengan panelnya.
        // Latar TERANG (CANVAS), sama persis dengan latar halaman dasbor HP —
        // supaya TV dan HP terasa satu permukaan. Kartu gelapnya berdiri di
        // atasnya seperti kartu saldo di dasbor.
        $field = Brand::gd($image, Brand::CANVAS);
        $ink = Brand::gd($image, Brand::BLUSH);
        $soft = Brand::gd($image, Brand::CHAMPAGNE);
        $accent = Brand::gd($image, Brand::COGNAC);
        $onAccent = Brand::gd($image, Brand::ESPRESSO);
        $panel = Brand::gd($image, Brand::BLUSH);
        $module = Brand::gd($image, Brand::ESPRESSO);

        imagefilledrectangle($image, 0, 0, self::WIDTH * $s, self::HEIGHT * $s, $field);

        $cardLeft = self::CARD_LEFT * $s;
        $cardTop = self::CARD_TOP * $s;
        $cardRight = ($cardLeft + self::CARD_WIDTH * $s);
        $cardBottom = ($cardTop + self::CARD_HEIGHT * $s);

        // Kartu = gradasi espresso→emerald, PERSIS seperti kartu saldo di
        // dasbor. Tanpa garis tepi: dulu perlu karena kartu gelap di atas latar
        // gelap (1,42:1); di latar terang, kartu gelapnya kontras sendiri.
        self::gradientCard($image, $cardLeft, $cardTop, $cardRight, $cardBottom, 44 * $s, $field);

        $bold = self::font(self::FONT_BOLD);
        $regular = self::font(self::FONT_REGULAR);

        // Teks rata tengah DI DALAM kartu, bukan di kanvas: kartunya kebetulan
        // memang di tengah sekarang, tapi menengahkan ke kanvas berarti setiap
        // pergeseran kartu diam-diam membuat isinya tidak lagi sejajar.
        $left = $cardLeft;
        $right = $cardRight;

        // Urutan bacanya disengaja: unit mana → tipe apa → apa yang harus
        // dilakukan → berapa.
        if ($bold) {
            self::text($image, $left, $right, $unit->code, $bold, 96, 190 * $s, $ink, 0.02);
            self::pill($image, $left, $right, mb_strtoupper($unit->unitType->name), $bold, 24, 250 * $s, $accent, $onAccent, 0.14);
        }

        self::qrPanel($image, $unit, $panel, $module);

        if ($bold) {
            self::text($image, $left, $right, 'PINDAI UNTUK MULAI', $bold, 36, 925 * $s, $ink, 0.10);
        }

        if ($regular) {
            $cheapest = Package::query()
                ->where('unit_type_id', $unit->unit_type_id)
                ->where('is_active', true)
                ->orderBy('price')
                ->first();

            self::text($image, $left, $right, $cheapest
                ? 'Mulai '.Rupiah::format($cheapest->price).'  ·  '.$cheapest->duration_minutes.' menit'
                : 'Hubungi kasir untuk mulai', $regular, 24, 968 * $s, $soft);

            self::text($image, $left, $right, 'CREATIVE TREES BILLING GAME', $regular, 14, 1004 * $s, $accent, 0.24);
        }

        return $image;
    }

    /**
     * Panel QR: kode memenuhi panelnya, tanpa kotak di dalam kotak. Zona
     * heningnya DIBENTUK oleh warna panel itu sendiri.
     */
    private static function qrPanel(GdImage $image, Unit $unit, int $panel, int $module): void
    {
        $s = self::SCALE;
        $x = self::QR_LEFT * $s;
        $y = self::QR_TOP * $s;
        $size = self::QR_SIZE * $s;

        self::roundedRect($image, $x, $y, $x + $size, $y + $size, 56 * $s, $panel);

        // Koreksi kesalahan tertinggi: layar TV memantulkan cahaya ruangan dan
        // dipindai dari jauh dengan sudut miring.
        $matrix = Encoder::encode(UnitQrCode::urlFor($unit), ErrorCorrectionLevel::H(), Encoder::DEFAULT_BYTE_MODE_ECODING)
            ->getMatrix();

        $modules = $matrix->getWidth();

        // Zona hening 3 modul. Spesifikasi QR menuntut 4, tapi itu untuk kode
        // cetak di atas latar sembarang; panel yang terang dan seragam sampai
        // sudut membulatnya menoleransi kurang. Dua tidak menyisakan margin
        // begitu pengambilannya miring dan tepi panel dilunakkan JPEG serta
        // pengecilan 2×.
        $quiet = 3;
        $cell = (int) ($size / ($modules + $quiet * 2));
        $origin = (int) (($size - $cell * $modules) / 2);

        // Modul sebagai TITIK terpisah, bukan kotak yang menyatu — gaya yang
        // dipilih pemilik produk.
        //
        // Awalnya saya kira ini mahal: titik berdiameter 0,92 sel kehilangan
        // 33,5% luas gelapnya dan tidak menyambung dengan tetangganya. Ternyata
        // TIDAK. Diukur dengan dekoder sungguhan (lihat KioskScreenTest), batas
        // pindainya jatuh di titik yang sama persis — sekitar 2,2 piksel per
        // modul — baik titiknya 0,55 maupun 0,92 sel. Yang menentukan bukan
        // tinta yang hilang, melainkan berapa piksel kamera yang jatuh di tiap
        // modul.
        //
        // Artinya: kalau pemindaian meleset di lapangan, memperbesar titik ini
        // TIDAK akan menolong. Yang harus diputar adalah ukuran panelnya
        // (QR_SIZE) atau jarak duduknya. 0,92 dipilih murni karena celah antar
        // titiknya masih terlihat pada layar 55 inci.
        $dot = (int) round($cell * 0.92);

        for ($my = 0; $my < $modules; $my++) {
            for ($mx = 0; $mx < $modules; $mx++) {
                if (self::isFinder($mx, $my, $modules) || $matrix->get($mx, $my) !== 1) {
                    continue;
                }

                imagefilledellipse(
                    $image,
                    (int) ($x + $origin + $mx * $cell + $cell / 2),
                    (int) ($y + $origin + $my * $cell + $cell / 2),
                    $dot, $dot,
                    $module,
                );
            }
        }

        // Penanda sudut sebagai CINCIN: lingkaran luar 7 modul, lubang 5 modul,
        // inti 3 modul. Perbandingan 1:1:3:1:1 yang dicari pemindai tetap utuh
        // di garis tengah mendatar maupun tegak — itu yang dibaca, bukan
        // kotaknya. Di luar garis tengah perbandingannya memang berbeda dari
        // penanda kotak, dan itu bagian dari margin yang menyempit di bawah.
        foreach ([[0, 0], [$modules - 7, 0], [0, $modules - 7]] as [$fx, $fy]) {
            $cx = (int) ($x + $origin + ($fx + 3.5) * $cell);
            $cy = (int) ($y + $origin + ($fy + 3.5) * $cell);

            imagefilledellipse($image, $cx, $cy, $cell * 7, $cell * 7, $module);
            imagefilledellipse($image, $cx, $cy, $cell * 5, $cell * 5, $panel);
            imagefilledellipse($image, $cx, $cy, $cell * 3, $cell * 3, $module);
        }
    }

    private static function isFinder(int $x, int $y, int $modules): bool
    {
        return ($x < 7 && $y < 7)
            || ($x >= $modules - 7 && $y < 7)
            || ($x < 7 && $y >= $modules - 7);
    }

    private static function font(array $candidates): ?string
    {
        foreach ($candidates as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Teks rata tengah DI DALAM sebuah kolom, dengan jarak antar huruf opsional.
     *
     * Lebarnya diukur dari SELURUH string sekaligus, bukan menjumlahkan lebar
     * tiap huruf. Penjumlahan per-huruf membuang kerning dan side bearing:
     * terukur di sini, 'PINDAI UNTUK MULAI' meleset ~2% — cukup untuk membuat
     * teks berjarak terlihat tidak benar-benar di tengah kolomnya. Jaraknya baru
     * ditambahkan setelah pengukuran.
     *
     * Jarak hurufnya digambar sendiri per karakter karena GD tidak punya
     * letter-spacing, dan pada layar besar huruf kapital rapat lebih sulit
     * dibaca dari jauh.
     */
    private static function text(GdImage $image, int $x1, int $x2, string $text, string $font, int $px, int $baseline, int $colour, float $tracking = 0): void
    {
        $size = self::gdSize($px);
        $x = $x1 + (($x2 - $x1) - self::widthOf($text, $font, $size, $tracking)) / 2;

        if ($tracking <= 0) {
            imagettftext($image, $size, 0, (int) $x, $baseline, $colour, $font, $text);

            return;
        }

        $letters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $gap = $size * $tracking;
        $prefix = '';

        foreach ($letters as $index => $letter) {
            $offset = 0;

            // Posisi tiap huruf diukur dari SELURUH awalan sebelumnya, bukan
            // dari akumulasi lebar per huruf — supaya kerning di dalam kata
            // tetap terpakai dan jaraknya benar-benar merata.
            if ($index > 0) {
                $box = imagettfbbox($size, 0, $font, $prefix);
                $offset = ($box[2] - $box[0]) + $gap * $index;
            }

            imagettftext($image, $size, 0, (int) ($x + $offset), $baseline, $colour, $font, $letter);
            $prefix .= $letter;
        }
    }

    private static function pill(GdImage $image, int $x1, int $x2, string $text, string $font, int $px, int $baseline, int $fill, int $ink, float $tracking = 0): void
    {
        $size = self::gdSize($px);
        $width = self::widthOf($text, $font, $size, $tracking);

        $padX = (int) ($size * 0.9);
        $padY = (int) ($size * 0.6);
        $centre = $x1 + ($x2 - $x1) / 2;

        self::roundedRect(
            $image,
            (int) ($centre - $width / 2) - $padX,
            $baseline - $size - $padY,
            (int) ($centre + $width / 2) + $padX,
            $baseline + $padY,
            (int) (($size + $padY * 2) / 2),
            $fill,
        );

        self::text($image, $x1, $x2, $text, $font, $px, $baseline, $ink, $tracking);
    }

    private static function gdSize(int $px): int
    {
        return (int) round($px * self::POINTS_PER_PIXEL * self::SCALE);
    }

    private static function widthOf(string $text, string $font, int $size, float $tracking): float
    {
        $box = imagettfbbox($size, 0, $font, $text);
        $letters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return ($box[2] - $box[0]) + ($size * $tracking) * max(0, count($letters) - 1);
    }

    /**
     * Kartu gradasi espresso→emerald dengan cahaya cognac lembut — meniru kartu
     * saldo di dasbor HP supaya keduanya identik.
     *
     * Gradasinya digambar kecil lalu diperbesar: peralihan warna halus identik
     * di mata, tapi jauh lebih cepat daripada per-piksel ukuran penuh. Sudutnya
     * dibulatkan dengan warna yang DIAMBIL dari gradasi di titik itu
     * (imagecolorat), jadi lengkungannya menyatu, bukan tempelan warna solid.
     */
    private static function gradientCard(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $bg): void
    {
        $w = $x2 - $x1;
        $h = $y2 - $y1;

        $sw = 96;
        $sh = max(1, (int) round($sw * $h / $w));
        $small = imagecreatetruecolor($sw, $sh);

        for ($yy = 0; $yy < $sh; $yy++) {
            for ($xx = 0; $xx < $sw; $xx++) {
                // 0 di kiri-atas (espresso) → 1 di kanan-bawah (emerald).
                $diag = (($xx / $sw) + ($yy / $sh)) / 2;
                $base = Brand::mix(Brand::ESPRESSO, Brand::EMERALD, $diag);

                // Cahaya cognac dari pojok kanan-atas, seperti kartu saldo.
                $gx = 1 - ($xx / $sw);
                $gy = $yy / $sh;
                $glow = max(0.0, 1 - sqrt($gx * $gx + $gy * $gy) / 0.6);

                imagesetpixel($small, $xx, $yy, Brand::gd($small, Brand::mix($base, Brand::COGNAC, $glow * 0.18)));
            }
        }

        imagecopyresampled($image, $small, $x1, $y1, 0, 0, $w, $h, $sw, $sh);
        unset($small);

        $midX = ($x1 + $x2) / 2;
        $midY = ($y1 + $y2) / 2;

        foreach ([[$x1 + $radius, $y1 + $radius], [$x2 - $radius, $y1 + $radius], [$x1 + $radius, $y2 - $radius], [$x2 - $radius, $y2 - $radius]] as [$cx, $cy]) {
            $local = imagecolorat($image, $cx, $cy);

            imagefilledrectangle(
                $image,
                $cx < $midX ? $x1 : $cx,
                $cy < $midY ? $y1 : $cy,
                $cx < $midX ? $cx : $x2,
                $cy < $midY ? $cy : $y2,
                $bg,
            );
            imagefilledellipse($image, $cx, $cy, $radius * 2, $radius * 2, $local);
        }
    }

    private static function roundedRect(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $colour): void
    {
        $radius = max(1, min($radius, (int) min(($x2 - $x1) / 2, ($y2 - $y1) / 2)));

        imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $colour);
        imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $colour);

        foreach ([[$x1 + $radius, $y1 + $radius], [$x2 - $radius, $y1 + $radius], [$x1 + $radius, $y2 - $radius], [$x2 - $radius, $y2 - $radius]] as [$cx, $cy]) {
            imagefilledellipse($image, $cx, $cy, $radius * 2, $radius * 2, $colour);
        }
    }
}
