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

        self::backdrop($image, $w, $h);

        $card = imagecolorallocate($image, 18, 20, 28);
        $line = imagecolorallocate($image, 38, 42, 56);
        $white = imagecolorallocate($image, 246, 247, 250);
        $muted = imagecolorallocate($image, 132, 140, 160);
        $accent = imagecolorallocate($image, 56, 189, 248);
        $panel = imagecolorallocate($image, 232, 240, 255);
        $module = imagecolorallocate($image, 11, 18, 32);

        // Kartu utama, dengan bayangan halus supaya terangkat dari latarnya.
        $cardW = 880 * $s;
        $cardH = 980 * $s;
        $cardX = (int) (($w - $cardW) / 2);
        $cardY = (int) (($h - $cardH) / 2);

        self::softShadow($image, $cardX, $cardY, $cardW, $cardH, 44 * $s);
        self::roundedRect($image, $cardX, $cardY, $cardX + $cardW, $cardY + $cardH, 44 * $s, $card);
        self::roundedOutline($image, $cardX, $cardY, $cardX + $cardW, $cardY + $cardH, 44 * $s, $line);

        $bold = self::font(self::FONT_BOLD);
        $regular = self::font(self::FONT_REGULAR);

        // Kode unit paling menonjol: pelanggan berdiri di depan beberapa TV dan
        // harus yakin sedang memindai yang benar sebelum membayar.
        if ($bold) {
            self::centreText($image, $w, $unit->code, $bold, 92 * $s, $cardY + 138 * $s, $white);
        }

        // Pil tipe unit, meniru bentuk label pada rujukan: satu kata pendek
        // yang harus terbaca tanpa bersaing dengan kode unit.
        if ($regular) {
            self::pill($image, $w, $unit->unitType->name, $regular, 30 * $s, $cardY + 200 * $s, $accent, $card);
        }

        // Panel QR: kode memenuhi panelnya sampai tepi. Tidak ada kotak putih
        // di dalam kotak — zona hening QR justru DIBENTUK oleh warna panel itu
        // sendiri, sehingga tetap bisa dipindai tanpa bingkai tambahan.
        $panelSize = 460 * $s;
        $panelX = (int) (($w - $panelSize) / 2);
        $panelY = $cardY + 268 * $s;

        self::softShadow($image, $panelX, $panelY, $panelSize, $panelSize, 56 * $s);
        self::roundedRect($image, $panelX, $panelY, $panelX + $panelSize, $panelY + $panelSize, 56 * $s, $panel);
        self::drawQr($image, $unit, $panelX, $panelY, $panelSize, $module, $panel);

        if ($bold) {
            self::centreText($image, $w, 'PINDAI UNTUK MULAI', $bold, 42 * $s, $panelY + $panelSize + 96 * $s, $white);
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

            self::centreText($image, $w, $harga, $regular, 30 * $s, $panelY + $panelSize + 152 * $s, $muted);
            self::centreText($image, $w, 'CREATIVE TREES BILLING GAME', $regular, 20 * $s, $cardY + $cardH - 48 * $s, $line);
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

        $dot = max(2, (int) ($cell * 0.86));

        for ($my = 0; $my < $modules; $my++) {
            for ($mx = 0; $mx < $modules; $mx++) {
                if (self::isFinder($mx, $my, $modules) || $matrix->get($mx, $my) !== 1) {
                    continue;
                }

                imagefilledellipse(
                    $image,
                    $x + $origin + $mx * $cell + (int) ($cell / 2),
                    $y + $origin + $my * $cell + (int) ($cell / 2),
                    $dot, $dot, $module,
                );
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

    private static function isFinder(int $x, int $y, int $modules): bool
    {
        return ($x < 7 && $y < 7)
            || ($x >= $modules - 7 && $y < 7)
            || ($x < 7 && $y >= $modules - 7);
    }

    /**
     * Gradasi + cahaya latar, digambar KECIL lalu diperbesar.
     *
     * Versi pertama menggambarnya pada resolusi penuh (3840×2160) dan memakan
     * 11 detik — tidak bisa dipakai untuk sesuatu yang diminta lewat HTTP.
     * Keduanya adalah peralihan warna yang sangat halus, jadi menggambarnya
     * pada 1/16 ukuran lalu memperbesar menghasilkan gambar yang sama persis
     * di mata, dalam waktu yang tidak terasa. Perbesaran justru MEMBANTU:
     * penghalusan saat resample menghapus sisa gerigi lingkarannya.
     */
    private static function backdrop(GdImage $image, int $w, int $h): void
    {
        $sw = (int) ($w / 16);
        $sh = (int) ($h / 16);
        $small = imagecreatetruecolor($sw, $sh);

        for ($y = 0; $y < $sh; $y++) {
            $t = $y / $sh;
            $colour = imagecolorallocate($small, (int) (8 + 9 * $t), (int) (10 + 13 * $t), (int) (18 + 24 * $t));
            imagefilledrectangle($small, 0, $y, $sw, $y, $colour);
        }

        $cx = (int) ($sw / 2);
        $cy = (int) ($sh / 2);
        $radius = (int) ($sw * 0.8);

        for ($r = $radius; $r > 0; $r--) {
            $t = 1 - ($r / $radius);
            $ease = $t * $t * $t;
            $colour = imagecolorallocate($small, (int) (9 + 8 * $ease), (int) (12 + 12 * $ease), (int) (22 + 22 * $ease));
            imagefilledellipse($small, $cx, $cy, $r * 2, $r * 2, $colour);
        }

        imagecopyresampled($image, $small, 0, 0, 0, 0, $w, $h, $sw, $sh);

        unset($small);
    }

    private static function softShadow(GdImage $image, int $x, int $y, int $w, int $h, int $radius): void
    {
        for ($i = 26; $i > 0; $i -= 2) {
            $shade = imagecolorallocatealpha($image, 0, 0, 0, 118);
            self::roundedRect($image, $x - $i, $y - $i + 6, $x + $w + $i, $y + $h + $i + 6, $radius + $i, $shade);
        }
    }

    private static function pill(GdImage $image, int $canvasWidth, string $text, string $font, int $size, int $y, int $fill, int $ink): void
    {
        $box = imagettfbbox($size, 0, $font, $text);
        $textWidth = $box[2] - $box[0];
        $padX = (int) ($size * 1.1);
        $padY = (int) ($size * 0.55);

        $x1 = (int) (($canvasWidth - $textWidth) / 2) - $padX;
        $x2 = (int) (($canvasWidth + $textWidth) / 2) + $padX;

        self::roundedRect($image, $x1, $y - $size - $padY, $x2, $y + $padY, (int) (($size + $padY * 2) / 2), $fill);
        imagettftext($image, $size, 0, (int) (($canvasWidth - $textWidth) / 2), $y, $ink, $font, $text);
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

    private static function centreText(GdImage $image, int $canvasWidth, string $text, string $font, int $size, int $y, int $colour): void
    {
        $box = imagettfbbox($size, 0, $font, $text);
        $width = $box[2] - $box[0];

        imagettftext($image, $size, 0, (int) (($canvasWidth - $width) / 2), $y, $colour, $font, $text);
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
