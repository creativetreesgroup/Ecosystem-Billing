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
     * Menghitung potongan/bonus dari nominal dasar. Persen dibulatkan ke bawah
     * (integer penuh — tak ada rupiah pecahan).
     *
     * $clampToBase menjepit hasil ke $base: BENAR untuk POTONGAN (diskon tak
     * boleh melampaui harga → harga akhir tak pernah minus), tapi SALAH untuk
     * BONUS isi saldo — bonus tetap boleh lebih besar dari nominal top-up
     * (mis. "isi 20rb, bonus 25rb"). Pemanggil menyetelnya per target.
     */
    public function discountOn(int $base, int $value, bool $clampToBase = true): int
    {
        $raw = $this === self::Percentage
            ? intdiv($base * $value, 100)
            : $value;

        return $clampToBase ? max(0, min($raw, $base)) : max(0, $raw);
    }
}
