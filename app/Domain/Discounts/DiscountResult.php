<?php

namespace App\Domain\Discounts;

/**
 * Hasil perhitungan diskon untuk sebuah transaksi: berapa dipotong, berapa yang
 * harus dibayar, dan diskon mana yang dipakai. Dipakai pratinjau UI maupun saat
 * menebus.
 */
final readonly class DiscountResult
{
    public function __construct(
        public int $discountId,
        public string $label,
        public int $discount,
        public int $finalAmount,
    ) {}
}
