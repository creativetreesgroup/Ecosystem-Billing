<?php

namespace App\Domain\Customers;

/**
 * Menyeragamkan nomor HP sebelum dipakai mencari akun.
 *
 * Orang yang sama menuliskan nomornya dengan cara berbeda tiap kali: 0812…,
 * +62812…, 62812…, dengan spasi atau tanda hubung. Tanpa penyeragaman, satu
 * orang bisa punya beberapa akun dengan saldo terpisah — dan yang menemukannya
 * adalah pelanggan yang saldonya "hilang".
 */
final class CustomerPhone
{
    public static function normalise(string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        // 62 di depan diubah jadi 0 supaya +6281…, 6281…, dan 081… menjadi
        // satu bentuk yang sama.
        if (str_starts_with($digits, '62')) {
            $digits = '0'.substr($digits, 2);
        }

        if (! str_starts_with($digits, '0')) {
            $digits = '0'.$digits;
        }

        // Nomor Indonesia yang masuk akal: 08xx dengan panjang 10–14 digit.
        if (! preg_match('/^08\d{8,12}$/', $digits)) {
            return null;
        }

        return $digits;
    }
}
