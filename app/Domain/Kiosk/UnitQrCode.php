<?php

namespace App\Domain\Kiosk;

use App\Models\Unit;
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
     * SVG siap disematkan di HTML tanpa berkas terpisah.
     */
    public static function dataUriFor(Unit $unit, int $size = 320): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svgFor($unit, $size));
    }
}
