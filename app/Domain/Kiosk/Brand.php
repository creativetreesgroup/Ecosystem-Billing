<?php

namespace App\Domain\Kiosk;

/**
 * Palet merek Creative Trees — SATU-SATUNYA sumber warna.
 *
 * Layar TV digambar PHP GD, halaman kios ditulis CSS tangan, dan panel memakai
 * warna Filament. Tanpa satu tempat seperti ini, hex yang sama ditulis ulang di
 * tiga berkas dan pelan-pelan menyimpang — versi sebelumnya sudah mulai begitu.
 *
 * Nilainya ditetapkan pemilik produk; JANGAN menambah warna di luar daftar ini.
 * Warna sorot kedua adalah cara tercepat membuat layar yang dilihat sekilas
 * jadi membingungkan: mata tidak tahu harus ke mana lebih dulu.
 */
final class Brand
{
    public const BLUSH = '#f9edf0';

    public const CHAMPAGNE = '#e6c8b7';

    public const COGNAC = '#c3955b';

    public const AMBER = '#ba6a36';

    public const EMERALD = '#1c3934';

    public const ESPRESSO = '#261311';

    /**
     * Off-white hangat — permukaan terang tema kios (latar halaman HP & layar
     * TV). Bukan warna sorot; ia latar tempat kartu gelap berdiri, dan disatukan
     * di sini supaya HP dan TV memakai latar yang sama persis.
     */
    public const CANVAS = '#f4f2ef';

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Warna GD dari hex merek.
     *
     * @param  \GdImage  $image
     */
    public static function gd($image, string $hex): int
    {
        [$r, $g, $b] = self::rgb($hex);

        return imagecolorallocate($image, $r, $g, $b);
    }

    /**
     * Campuran dua warna merek. Dipakai untuk keadaan antara (garis pemisah,
     * teks redup) tanpa memperkenalkan warna baru ke dalam palet.
     */
    public static function mix(string $from, string $to, float $amount): string
    {
        [$r1, $g1, $b1] = self::rgb($from);
        [$r2, $g2, $b2] = self::rgb($to);

        return sprintf(
            '#%02x%02x%02x',
            (int) round($r1 + ($r2 - $r1) * $amount),
            (int) round($g1 + ($g2 - $g1) * $amount),
            (int) round($b1 + ($b2 - $b1) * $amount),
        );
    }

    /**
     * Rasio kontras WCAG antara dua warna merek.
     *
     * Ada di sini supaya klaim keterbacaan bisa DIUJI, bukan diperkirakan:
     * kode QR di layar TV yang kontrasnya kurang tidak akan terbaca pemindai,
     * dan itu kegagalan yang baru ketahuan saat pelanggan sudah berdiri di
     * depan TV sambil mengangkat HP.
     */
    public static function contrast(string $a, string $b): float
    {
        $luminance = static function (string $hex): float {
            $channels = array_map(static function (int $value): float {
                $c = $value / 255;

                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            }, self::rgb($hex));

            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        };

        $light = $luminance($a);
        $dark = $luminance($b);

        if ($light < $dark) {
            [$light, $dark] = [$dark, $light];
        }

        return round(($light + 0.05) / ($dark + 0.05), 2);
    }
}
