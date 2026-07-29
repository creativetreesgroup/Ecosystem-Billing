<?php

namespace App\Domain\Customers;

/**
 * ID kartu pelanggan — 16 karakter huruf-angka kapital, ditampilkan seperti
 * nomor kartu (empat kelompok berisi empat).
 *
 * Ini identitas yang dibacakan pelanggan ke kasir, bukan nomor mesin. Karena
 * itu alfabetnya membuang karakter yang mudah tertukar saat diucapkan atau
 * dibaca sekilas — 0/O, 1/I/L — sehingga tidak ada "kartu saya AB1O" yang
 * ternyata "ABIO". Sisanya 32 simbol; 32^16 ≈ 1,2 × 10^24 kombinasi, jadi
 * tabrakan praktis mustahil dan pengecekan keunikan di model hanya pengaman.
 *
 * Di kartu, nomornya DITAMPILKAN TERSAMAR: cuma empat karakter terakhir yang
 * terlihat, sisanya titik — persis seperti kartu sungguhan. Nomor utuh tidak
 * pernah tampil di layar pelanggan.
 */
final class CardNumber
{
    public const LENGTH = 16;

    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $number = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $number .= self::ALPHABET[random_int(0, $max)];
        }

        return $number;
    }

    /** "ABCD EFGH JKLM NPQR" — hanya dipakai di tempat yang berhak melihat utuh. */
    public static function format(string $number): string
    {
        return trim(chunk_split($number, 4, ' '));
    }

    /** "•••• •••• •••• NPQR" — yang tampil ke pelanggan. */
    public static function masked(string $number): string
    {
        return '•••• •••• •••• '.substr($number, -4);
    }

    /** Dua karakter pertama, ditampilkan kecil sebagai "CVC" di muka kartu. */
    public static function cvc(string $number): string
    {
        return substr($number, 0, 2);
    }
}
