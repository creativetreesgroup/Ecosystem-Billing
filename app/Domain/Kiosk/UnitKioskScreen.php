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
 *
 * Konsekuensinya: TIDAK ADA animasi. Gerak butuh video (ffmpeg) atau GIF
 * animasi yang dukungannya tidak konsisten antar perangkat Cast. Kedalaman
 * visualnya dikerjakan lewat gradasi, cahaya, dan bayangan — bukan gerak.
 *
 * Semuanya digambar pada 2× lalu diperkecil (supersampling). GD tidak punya
 * antialias untuk bentuk terisi, jadi lingkaran dan sudut membulat akan
 * bergerigi kalau digambar langsung pada ukuran akhir — dan gerigi pada
 * layar 55 inci terlihat jelas dari kursi pelanggan.
 */
final class UnitKioskScreen
{
    private const WIDTH = 1920;

    private const HEIGHT = 1080;

    /** Digambar sebesar ini lalu diperkecil, demi tepi yang halus. */
    private const SCALE = 2;

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
        // begitu hasilnya ikut disimpan ke cache — gagalnya berupa proses mati
        // tanpa pesan, yang jauh lebih sulit dilacak daripada exception.
        unset($big);

        ob_start();
        imagejpeg($final, quality: 94);
        $jpeg = (string) ob_get_clean();

        unset($final);

        return $jpeg;
    }

    private static function draw(Unit $unit): GdImage
    {
        $w = self::WIDTH * self::SCALE;
        $h = self::HEIGHT * self::SCALE;
        $s = self::SCALE;

        $image = imagecreatetruecolor($w, $h);

        // Palet dari pemilik produk: espresso #261311 sebagai latar, emerald
        // #1c3934 sebagai kartu. Sisanya diturunkan dari keduanya — krem hangat
        // untuk panel QR (kontras tertinggi terhadap espresso tanpa terasa
        // dingin seperti putih murni), dan emas lembut sebagai satu-satunya
        // aksen. Satu aksen saja: dua warna sorot pada layar yang dilihat
        // sekilas justru membuat mata bingung harus ke mana dulu.
        $card = imagecolorallocate($image, 28, 57, 52);      // emerald #1c3934
        $line = imagecolorallocate($image, 44, 78, 71);
        $white = imagecolorallocate($image, 244, 239, 231);
        $muted = imagecolorallocate($image, 147, 168, 162);
        $accent = imagecolorallocate($image, 216, 166, 87);
        $panel = imagecolorallocate($image, 244, 238, 228);
        $module = imagecolorallocate($image, 26, 13, 12);    // espresso pekat

        // Kartu utama, dengan bayangan halus supaya terangkat dari latarnya.
        $cardW = 880 * $s;
        $cardH = 1000 * $s;
        $cardX = (int) (($w - $cardW) / 2);
        $cardY = (int) (($h - $cardH) / 2);

        self::backdrop($image, $w, $h);
        self::roundedRect($image, $cardX, $cardY, $cardX + $cardW, $cardY + $cardH, 44 * $s, $card);
        self::roundedOutline($image, $cardX, $cardY, $cardX + $cardW, $cardY + $cardH, 44 * $s, $line);

        $bold = self::font(self::FONT_BOLD);
        $regular = self::font(self::FONT_REGULAR);

        // Kode unit paling menonjol: pelanggan berdiri di depan beberapa TV dan
        // harus yakin sedang memindai yang benar sebelum membayar.
        if ($bold) {
            self::centreText($image, $w, $unit->code, $bold, 104 * $s, $cardY + 152 * $s, $white);
        }

        // Pil tipe unit, meniru bentuk label pada rujukan: satu kata pendek
        // yang harus terbaca tanpa bersaing dengan kode unit.
        if ($regular) {
            self::pill($image, $w, mb_strtoupper($unit->unitType->name), $bold ?? $regular, 24 * $s, $cardY + 214 * $s, $accent, $card, 0.14);
        }

        // Panel QR: kode memenuhi panelnya sampai tepi. Tidak ada kotak putih
        // di dalam kotak — zona hening QR justru DIBENTUK oleh warna panel itu
        // sendiri, sehingga tetap bisa dipindai tanpa bingkai tambahan.
        $panelSize = 460 * $s;
        $panelX = (int) (($w - $panelSize) / 2);
        $panelY = $cardY + 286 * $s;

        self::roundedRect($image, $panelX, $panelY, $panelX + $panelSize, $panelY + $panelSize, 56 * $s, $panel);
        self::drawQr($image, $unit, $panelX, $panelY, $panelSize, $module, $panel);

        if ($bold) {
            self::centreText($image, $w, 'PINDAI UNTUK MULAI', $bold, 38 * $s, $panelY + $panelSize + 100 * $s, $white, 0.10);
        }

        if ($regular) {
            $cheapest = Package::query()
                ->where('unit_type_id', $unit->unit_type_id)
                ->where('is_active', true)
                ->orderBy('price')
                ->first();

            $harga = $cheapest
                ? 'Mulai '.Rupiah::format($cheapest->price).'  ·  '.$cheapest->duration_minutes.' menit'
                : 'Hubungi kasir untuk mulai';

            self::centreText($image, $w, $harga, $regular, 26 * $s, $panelY + $panelSize + 158 * $s, $muted);
            self::centreText($image, $w, 'CREATIVE TREES BILLING GAME', $regular, 15 * $s, $cardY + $cardH - 40 * $s, $line, 0.24);
        }

        return $image;
    }

    /**
     * Kode QR dengan modul BULAT dan penanda sudut membulat.
     *
     * Digambar sendiri dari matriksnya, bukan lewat renderer bawaan, karena
     * yang tersedia di sana hanya kotak tajam. Bentuk bulat lebih enak dipandang
     * dan tetap terbaca: pemindai membaca posisi modul, bukan bentuknya, selama
     * kontras dan zona heningnya terjaga.
     */
    private static function drawQr(GdImage $image, Unit $unit, int $x, int $y, int $panelSize, int $module, int $panel): void
    {
        // Koreksi kesalahan tinggi (H): layar TV memantulkan cahaya ruangan dan
        // dipindai dari jarak jauh dengan sudut miring — kode yang sebagian
        // terbaca kabur tetap harus bisa dipulihkan.
        $matrix = Encoder::encode(UnitQrCode::urlFor($unit), ErrorCorrectionLevel::H(), Encoder::DEFAULT_BYTE_MODE_ECODING)
            ->getMatrix();

        $modules = $matrix->getWidth();

        // Zona hening 2 modul: batas aman menurut spesifikasi QR adalah 4, tapi
        // itu untuk kode cetak di atas latar sembarang. Di sini panelnya
        // sendiri terang dan seragam sampai sudut membulatnya, jadi 2 sudah
        // cukup — dan kodenya jadi memenuhi panel, bukan mengambang di
        // tengahnya dengan bingkai putih lebar.
        $quiet = 2;
        $cell = (int) ($panelSize / ($modules + $quiet * 2));
        $origin = (int) (($panelSize - $cell * $modules) / 2);

        // Modul digambar sebagai kotak yang sudutnya HANYA dibulatkan bila
        // tidak ada tetangga di kedua sisi sudut itu. Hasilnya modul yang
        // bersebelahan menyatu jadi bentuk mengalir, sementara modul menyendiri
        // menjadi lingkaran penuh.
        //
        // Aman dipindai: pusat tiap modul tetap terisi penuh, dan pemindai
        // membaca posisi modul — bukan bentuknya. Yang tidak boleh diganggu
        // hanyalah kontras dan zona hening, dan keduanya utuh.
        $radius = (int) ($cell / 2);

        for ($my = 0; $my < $modules; $my++) {
            for ($mx = 0; $mx < $modules; $mx++) {
                if (self::isFinder($mx, $my, $modules) || $matrix->get($mx, $my) !== 1) {
                    continue;
                }

                $left = $x + $origin + $mx * $cell;
                $top = $y + $origin + $my * $cell;

                imagefilledrectangle($image, $left, $top, $left + $cell, $top + $cell, $module);

                $atas = self::isDark($matrix, $mx, $my - 1, $modules);
                $bawah = self::isDark($matrix, $mx, $my + 1, $modules);
                $kiri = self::isDark($matrix, $mx - 1, $my, $modules);
                $kanan = self::isDark($matrix, $mx + 1, $my, $modules);

                foreach ([
                    [! $atas && ! $kiri, $left, $top, 1, 1],
                    [! $atas && ! $kanan, $left + $cell, $top, -1, 1],
                    [! $bawah && ! $kiri, $left, $top + $cell, 1, -1],
                    [! $bawah && ! $kanan, $left + $cell, $top + $cell, -1, -1],
                ] as [$bulatkan, $cxCorner, $cyCorner, $dx, $dy]) {
                    if (! $bulatkan) {
                        continue;
                    }

                    // Potong sudutnya dengan warna panel, lalu kembalikan
                    // lengkungannya dengan seperempat lingkaran.
                    imagefilledrectangle(
                        $image,
                        $cxCorner, $cyCorner,
                        $cxCorner + $radius * $dx, $cyCorner + $radius * $dy,
                        $panel,
                    );
                    imagefilledellipse(
                        $image,
                        $cxCorner + $radius * $dx, $cyCorner + $radius * $dy,
                        $radius * 2, $radius * 2,
                        $module,
                    );
                }
            }
        }

        // Tiga penanda sudut digambar sebagai cincin membulat — bagian inilah
        // yang pertama dicari pemindai, jadi bentuknya harus tegas.
        foreach ([[0, 0], [$modules - 7, 0], [0, $modules - 7]] as [$fx, $fy]) {
            $left = $x + $origin + $fx * $cell;
            $top = $y + $origin + $fy * $cell;
            $side = $cell * 7;

            self::roundedRect($image, $left, $top, $left + $side, $top + $side, (int) ($side * 0.28), $module);
            self::roundedRect($image, $left + $cell, $top + $cell, $left + $side - $cell, $top + $side - $cell, (int) ($side * 0.2), $panel);
            self::roundedRect($image, $left + $cell * 2, $top + $cell * 2, $left + $side - $cell * 2, $top + $side - $cell * 2, (int) ($side * 0.14), $module);
        }
    }

    private static function isDark(mixed $matrix, int $x, int $y, int $modules): bool
    {
        if ($x < 0 || $y < 0 || $x >= $modules || $y >= $modules) {
            return false;
        }

        // Penanda sudut digambar terpisah, jadi ia tidak boleh dianggap
        // tetangga — kalau tidak, modul di sebelahnya ikut menyatu ke sana dan
        // bentuk penandanya rusak. Justru bentuk itu yang pertama dicari
        // pemindai.
        if (self::isFinder($x, $y, $modules)) {
            return false;
        }

        return $matrix->get($x, $y) === 1;
    }

    private static function isFinder(int $x, int $y, int $modules): bool
    {
        return ($x < 7 && $y < 7)
            || ($x >= $modules - 7 && $y < 7)
            || ($x < 7 && $y >= $modules - 7);
    }

    /**
     * Latar espresso dengan cahaya sangat halus di belakang kartu.
     *
     * Digambar pada 1/8 ukuran lalu diperbesar: gradasi dan cahaya adalah
     * peralihan warna halus, jadi hasilnya identik di mata sementara waktunya
     * turun dari sebelas detik ke bawah satu detik. Penghalusan saat
     * memperbesar sekaligus menghapus sisa gerigi lingkarannya.
     *
     * TANPA bayangan kartu. Sempat ada, dan di layar TV justru terlihat seperti
     * kotoran di sekeliling kartu, bukan kedalaman — pemilik produk memintanya
     * dihapus setelah melihatnya langsung. Pemisahan kartu dari latar kini
     * dikerjakan kontras warna (emerald di atas espresso), yang jauh lebih
     * bersih dari jarak beberapa meter.
     */
    private static function backdrop(GdImage $image, int $w, int $h): void
    {
        $div = 8;
        $sw = (int) ($w / $div);
        $sh = (int) ($h / $div);
        $small = imagecreatetruecolor($sw, $sh);

        for ($y = 0; $y < $sh; $y++) {
            $t = $y / $sh;
            $colour = imagecolorallocate($small, (int) (38 - 6 * $t), (int) (19 - 3 * $t), (int) (17 - 2 * $t));
            imagefilledrectangle($small, 0, $y, $sw, $y, $colour);
        }

        // Radius melebihi kanvas supaya tepi lingkarannya tidak pernah terlihat
        // sebagai garis di layar.
        $cx = (int) ($sw / 2);
        $cy = (int) ($sh / 2);
        $radius = (int) ($sw * 0.95);

        for ($r = $radius; $r > 0; $r--) {
            $t = 1 - ($r / $radius);
            $ease = $t * $t * $t * $t;
            $colour = imagecolorallocate($small, (int) (36 + 14 * $ease), (int) (19 + 10 * $ease), (int) (17 + 8 * $ease));
            imagefilledellipse($small, $cx, $cy, $r * 2, $r * 2, $colour);
        }

        imagecopyresampled($image, $small, 0, 0, 0, 0, $w, $h, $sw, $sh);

        unset($small);
    }

    private static function pill(GdImage $image, int $canvasWidth, string $text, string $font, int $size, int $y, int $fill, int $ink, float $tracking = 0): void
    {
        $letters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $gap = $size * $tracking;

        $textWidth = -$gap;

        foreach ($letters as $letter) {
            $box = imagettfbbox($size, 0, $font, $letter);
            $textWidth += ($box[2] - $box[0]) + $gap;
        }

        $padX = (int) ($size * 1.4);
        $padY = (int) ($size * 0.62);

        $x1 = (int) (($canvasWidth - $textWidth) / 2) - $padX;
        $x2 = (int) (($canvasWidth + $textWidth) / 2) + $padX;

        self::roundedRect($image, $x1, $y - $size - $padY, $x2, $y + $padY, (int) (($size + $padY * 2) / 2), $fill);
        self::centreText($image, $canvasWidth, $text, $font, $size, $y, $ink, $tracking);
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
     * Teks rata tengah, dengan jarak antar huruf opsional.
     *
     * Jarak huruf digambar sendiri per karakter karena GD tidak punya
     * letter-spacing. Itu yang membedakan label kecil huruf kapital terlihat
     * dirancang atau sekadar diketik — dan pada layar besar, huruf kapital
     * rapat justru lebih sulit dibaca dari jauh.
     */
    private static function centreText(GdImage $image, int $canvasWidth, string $text, string $font, int $size, int $y, int $colour, float $tracking = 0): void
    {
        if ($tracking <= 0) {
            $box = imagettfbbox($size, 0, $font, $text);
            imagettftext($image, $size, 0, (int) (($canvasWidth - ($box[2] - $box[0])) / 2), $y, $colour, $font, $text);

            return;
        }

        $letters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $gap = $size * $tracking;

        $width = -$gap;

        foreach ($letters as $letter) {
            $box = imagettfbbox($size, 0, $font, $letter);
            $width += ($box[2] - $box[0]) + $gap;
        }

        $x = ($canvasWidth - $width) / 2;

        foreach ($letters as $letter) {
            imagettftext($image, $size, 0, (int) $x, $y, $colour, $font, $letter);
            $box = imagettfbbox($size, 0, $font, $letter);
            $x += ($box[2] - $box[0]) + $gap;
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

    private static function roundedOutline(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $colour): void
    {
        imageline($image, $x1 + $radius, $y1, $x2 - $radius, $y1, $colour);
        imageline($image, $x1 + $radius, $y2, $x2 - $radius, $y2, $colour);
        imageline($image, $x1, $y1 + $radius, $x1, $y2 - $radius, $colour);
        imageline($image, $x2, $y1 + $radius, $x2, $y2 - $radius, $colour);
    }
}
