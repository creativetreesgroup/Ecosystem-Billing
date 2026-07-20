<?php

namespace App\Domain\Kiosk;

use App\Models\Unit;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Kode QR yang ditempel di tiap unit.
 *
 * Keluarannya SVG, bukan PNG: stiker dicetak dalam berbagai ukuran (di bingkai
 * TV, di meja) dan SVG tetap tajam di semuanya. SVG juga tidak menuntut
 * ekstensi imagick, sehingga mesin outlet tidak perlu dipasangi apa pun lagi.
 */
final class UnitQrCode
{
    /**
     * Tautan yang dipindai pelanggan.
     *
     * Memakai APP_URL, jadi kalau panel dipindahkan ke mesin outlet dengan IP
     * berbeda, SELURUH stiker harus dicetak ulang. Itu disengaja terlihat:
     * alamat yang salah membuat pelanggan memindai kode yang tidak menuju
     * ke mana pun, dan tidak ada pesan kesalahan yang bisa menjelaskannya.
     */
    public static function urlFor(Unit $unit): string
    {
        return route('kiosk.unit', ['unit' => $unit->code]);
    }

    public static function svgFor(Unit $unit, int $size = 320): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle($size, margin: 2),
            new SvgImageBackEnd,
        ));

        return $writer->writeString(self::urlFor($unit));
    }

    /**
     * PNG untuk ditampilkan DI LAYAR TV lewat Google Cast.
     *
     * Cast hanya menerima gambar raster — SVG ditolak. Digambar dengan GD dari
     * matriks QR-nya langsung, bukan lewat imagick: imagick berarti ekstensi
     * tambahan yang harus dipasang di mesin outlet, sedangkan GD sudah ada di
     * hampir semua PHP dan yang digambar cuma kotak hitam-putih.
     *
     * Latar putih dengan margin lebar bukan pilihan gaya: pemindai QR butuh
     * kontras tinggi dan area kosong di sekeliling kode, dan layar TV dilihat
     * dari jarak beberapa meter.
     */
    public static function pngFor(Unit $unit, int $scale = 12, int $margin = 4): string
    {
        $matrix = Encoder::encode(self::urlFor($unit), ErrorCorrectionLevel::M(), Encoder::DEFAULT_BYTE_MODE_ECODING)
            ->getMatrix();

        $modules = $matrix->getWidth();
        $size = ($modules + $margin * 2) * $scale;

        $image = imagecreatetruecolor($size, $size);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $size, $size, $white);

        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    imagefilledrectangle(
                        $image,
                        ($x + $margin) * $scale,
                        ($y + $margin) * $scale,
                        ($x + $margin + 1) * $scale - 1,
                        ($y + $margin + 1) * $scale - 1,
                        $black,
                    );
                }
            }
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * SVG siap disematkan di HTML tanpa berkas terpisah.
     */
    public static function dataUriFor(Unit $unit, int $size = 320): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svgFor($unit, $size));
    }
}
