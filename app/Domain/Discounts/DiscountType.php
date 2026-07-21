<?php

namespace App\Domain\Discounts;

use Filament\Support\Contracts\HasLabel;

enum DiscountType: string implements HasLabel
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Percentage => 'Persen (%)',
            self::Fixed => 'Nominal tetap (Rp)',
        };
    }

    /**
     * Menghitung potongan dari nominal dasar. Selalu dijepit ke $base supaya
     * diskon tidak pernah melampaui harganya (harga akhir tak pernah minus), dan
     * persen dibulatkan ke bawah (integer penuh — tak ada rupiah pecahan).
     */
    public function discountOn(int $base, int $value): int
    {
        $raw = $this === self::Percentage
            ? intdiv($base * $value, 100)
            : $value;

        return max(0, min($raw, $base));
    }
}
