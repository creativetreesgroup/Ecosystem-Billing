<?php

namespace App\Domain\Billing;

class OpenPlayBillingCalculator
{
    public static function calculate(int $elapsedSeconds, int $hourlyRateRupiah, int $incrementMinutes): int
    {
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
