<?php

namespace App\Domain\Billing;

class OpenPlayBillingCalculator
{
    public static function calculate(int $elapsedSeconds, int $hourlyRateRupiah, int $incrementMinutes): int
    {
        // Pembulatan 0 menit tidak punya arti dan dulu meledak jadi
        // DivisionByZeroError tepat saat kasir menutup sesi — sesi jadi
        // tidak bisa diselesaikan sama sekali. Diperlakukan sebagai 1 menit
        // (pembulatan terhalus) supaya penagihan tetap jalan; validasi form
        // tetap mencegah nilainya tersimpan (lihat SettingForm).
        $incrementMinutes = max(1, $incrementMinutes);

        if ($elapsedSeconds <= 0) {
            return 0;
        }

        $incrementSeconds = $incrementMinutes * 60;
        $billableMinutes = intdiv(self::ceilDiv($elapsedSeconds, $incrementSeconds) * $incrementSeconds, 60);

        return self::ceilDiv($billableMinutes * $hourlyRateRupiah, 60);
    }

    private static function ceilDiv(int $numerator, int $denominator): int
    {
        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
