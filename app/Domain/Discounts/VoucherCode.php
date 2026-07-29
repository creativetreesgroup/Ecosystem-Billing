<?php

namespace App\Domain\Discounts;

use App\Models\Discount;
use Illuminate\Support\Str;

/**
 * Kode voucher acak — bukan turunan nama diskonnya.
 *
 * Kode yang berasal dari nama ("TOPBESAR", "HEMAT20") bisa DITEBAK: siapa pun
 * yang pernah melihat satu spanduk promo bisa mencoba variasinya di kios sampai
 * ada yang tembus, dan kuota voucher habis oleh orang yang tak pernah diberi.
 *
 * Bentuknya sengaja BUKAN UUID penuh maupun 16 digit ala nomor kartu. Kode ini
 * diketik manusia di layar sentuh kios sambil berdiri — 36 karakter membuatnya
 * mustahil, dan setiap karakter tambahan adalah satu peluang salah ketik yang
 * berakhir dengan "kodenya tidak berlaku" padahal berlaku.
 *
 * Delapan karakter dari alfabet yang tidak ambigu memberi ~1,1 triliun
 * kemungkinan — jauh melebihi jumlah voucher yang akan pernah dibuat outlet ini,
 * sekaligus tetap muat dibaca dari selembar struk.
 */
final class VoucherCode
{
    /**
     * Tanpa 0/O, 1/I/L, 5/S, 8/B, 2/Z — pasangan yang tertukar saat kode
     * dibacakan lewat telepon atau disalin dari struk termal yang pucat.
     */
    private const ALPHABET = '34679ACDEFGHJKMNPQRTUVWXY';

    private const LENGTH = 8;

    private const MAX_ATTEMPTS = 20;

    /** Kode unik baru, mis. "K7QM-3XAF". */
    public static function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $code = self::random();

            if (! Discount::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        // Praktis tak akan tercapai; kalau tercapai, sesuatu yang lebih besar
        // sedang salah dan kode yang bentrok diam-diam jauh lebih berbahaya
        // daripada galat yang terlihat.
        throw new \RuntimeException('Gagal membuat kode voucher unik setelah '.self::MAX_ATTEMPTS.' percobaan.');
    }

    private static function random(): string
    {
        $raw = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        // Dipisah tanda hubung: mata membaca dua blok empat karakter jauh lebih
        // cepat daripada satu deret delapan, dan itu mengurangi salah ketik.
        return Str::of($raw)->substr(0, 4).'-'.Str::of($raw)->substr(4);
    }
}
