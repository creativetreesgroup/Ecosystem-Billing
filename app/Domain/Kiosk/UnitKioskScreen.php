<?php

namespace App\Domain\Kiosk;

use App\Domain\Billing\Rupiah;
use App\Models\Package;
use App\Models\Unit;
use GdImage;

/**
 * Layar yang ditampilkan DI TV saat unit menganggur.
 *
 * Digambar utuh sebagai satu gambar, bukan halaman web, karena Google Cast
 * hanya bisa menampilkan gambar diam — halaman web butuh receiver Cast
 * terdaftar Google beserta HTTPS publik, yang bentrok dengan panel LAN-only.
 *
 * Ukurannya 1920×1080 dan semuanya besar: ini dibaca dari jarak beberapa meter,
 * bukan dari genggaman. Kode QR diberi latar putih lebar karena pemindai butuh
 * kontras tinggi dan ruang kosong di sekelilingnya.
 */
final class UnitKioskScreen
{
    private const WIDTH = 1920;

    private const HEIGHT = 1080;

    /**
     * Font dicari dari beberapa lokasi umum, bukan dibundel.
     *
     * Membundel berkas font berarti menambah aset biner ke repositori untuk
     * sesuatu yang hampir selalu sudah ada di mesinnya. Kalau tidak satu pun
     * ditemukan, layarnya tetap tampil — hanya tanpa teks, dan kode QR-nya
     * tetap bisa dipindai.
     */
    private const FONT_CANDIDATES = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
        '/Library/Fonts/Arial Bold.ttf',
    ];

    private const FONT_REGULAR_CANDIDATES = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        '/System/Library/Fonts/Supplemental/Arial.ttf',
        '/Library/Fonts/Arial.ttf',
    ];

    public static function jpegFor(Unit $unit): string
    {
        $canvas = self::draw($unit);

        ob_start();
        imagejpeg($canvas, quality: 92);

        return (string) ob_get_clean();
    }

    private static function draw(Unit $unit): GdImage
    {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);

        $bg = imagecolorallocate($image, 10, 10, 11);
        $card = imagecolorallocate($image, 23, 23, 26);
        $line = imagecolorallocate($image, 42, 42, 48);
        $white = imagecolorallocate($image, 244, 244, 245);
        $muted = imagecolorallocate($image, 139, 139, 148);
        $accent = imagecolorallocate($image, 245, 158, 11);
        $paper = imagecolorallocate($image, 250, 248, 240);

        imagefilledrectangle($image, 0, 0, self::WIDTH, self::HEIGHT, $bg);

        // Kartu di tengah.
        $cardW = 900;
        $cardH = 940;
        $cardX = (int) ((self::WIDTH - $cardW) / 2);
        $cardY = (int) ((self::HEIGHT - $cardH) / 2);
        self::roundedRect($image, $cardX, $cardY, $cardX + $cardW, $cardY + $cardH, 40, $card);
        self::roundedRectOutline($image, $cardX, $cardY, $cardX + $cardW, $cardY + $cardH, 40, $line);

        $bold = self::font(self::FONT_CANDIDATES);
        $regular = self::font(self::FONT_REGULAR_CANDIDATES);

        // Kode unit adalah hal pertama yang dicari mata: pelanggan berdiri di
        // depan beberapa TV dan harus yakin sedang memindai yang benar.
        if ($bold) {
            self::centreText($image, $unit->code, $bold, 92, $cardY + 145, $white);
        }

        if ($regular) {
            self::centreText($image, $unit->unitType->name, $regular, 32, $cardY + 200, $muted);
        }

        // Panel QR: putih, lebar, dengan jarak dari tepi.
        $qrPanel = 440;
        $qrX = (int) ((self::WIDTH - $qrPanel) / 2);
        $qrY = $cardY + 250;
        self::roundedRect($image, $qrX, $qrY, $qrX + $qrPanel, $qrY + $qrPanel, 28, $paper);

        $qr = imagecreatefromstring(UnitQrCode::pngFor($unit, scale: 10, margin: 2));
        $qrSize = $qrPanel - 60;
        imagecopyresampled(
            $image, $qr,
            $qrX + 30, $qrY + 30, 0, 0,
            $qrSize, $qrSize,
            imagesx($qr), imagesy($qr),
        );

        // Kurung sudut: penanda visual "pindai di sini" yang dikenali orang
        // tanpa perlu membaca satu kata pun.
        self::scanBrackets($image, $qrX - 34, $qrY - 34, $qrPanel + 68, $accent);

        if ($bold) {
            self::centreText($image, 'PINDAI UNTUK MULAI MAIN', $bold, 38, $qrY + $qrPanel + 95, $accent);
        }

        if ($regular) {
            $cheapest = Package::query()
                ->where('unit_type_id', $unit->unit_type_id)
                ->where('is_active', true)
                ->orderBy('price')
                ->first();

            $harga = $cheapest
                ? 'Mulai '.Rupiah::format($cheapest->price).' · '.$cheapest->duration_minutes.' menit'
                : 'Hubungi kasir untuk mulai';

            self::centreText($image, $harga, $regular, 28, $qrY + $qrPanel + 150, $muted);
            self::centreText($image, 'Creative Trees Billing Game', $regular, 22, $cardY + $cardH - 38, $line);
        }

        return $image;
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

    private static function centreText(GdImage $image, string $text, string $font, int $size, int $y, int $colour): void
    {
        $box = imagettfbbox($size, 0, $font, $text);
        $width = $box[2] - $box[0];

        imagettftext($image, $size, 0, (int) ((self::WIDTH - $width) / 2), $y, $colour, $font, $text);
    }

    private static function roundedRect(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $colour): void
    {
        imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $colour);
        imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $colour);

        foreach ([[$x1 + $radius, $y1 + $radius], [$x2 - $radius, $y1 + $radius], [$x1 + $radius, $y2 - $radius], [$x2 - $radius, $y2 - $radius]] as [$cx, $cy]) {
            imagefilledellipse($image, $cx, $cy, $radius * 2, $radius * 2, $colour);
        }
    }

    private static function roundedRectOutline(GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $colour): void
    {
        imageline($image, $x1 + $radius, $y1, $x2 - $radius, $y1, $colour);
        imageline($image, $x1 + $radius, $y2, $x2 - $radius, $y2, $colour);
        imageline($image, $x1, $y1 + $radius, $x1, $y2 - $radius, $colour);
        imageline($image, $x2, $y1 + $radius, $x2, $y2 - $radius, $colour);
    }

    private static function scanBrackets(GdImage $image, int $x, int $y, int $size, int $colour): void
    {
        $arm = 60;
        $thickness = 6;

        foreach ([[0, 0, 1, 1], [$size, 0, -1, 1], [0, $size, 1, -1], [$size, $size, -1, -1]] as [$ox, $oy, $dx, $dy]) {
            imagefilledrectangle($image, $x + $ox, $y + $oy, $x + $ox + $arm * $dx, $y + $oy + $thickness * $dy, $colour);
            imagefilledrectangle($image, $x + $ox, $y + $oy, $x + $ox + $thickness * $dx, $y + $oy + $arm * $dy, $colour);
        }
    }
}
