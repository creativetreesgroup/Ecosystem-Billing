<?php

namespace App\Domain\Discounts;

/**
 * Hasil perhitungan diskon untuk sebuah transaksi: nama diskonnya, berapa
 * dipotong, dan berapa yang harus dibayar. Dipakai pratinjau UI (menampilkan
 * potongan sebelum bayar).
 */
final readonly class DiscountResult
{
    public function __construct(
        public string $label,
        public int $discount,
        public int $finalAmount,
    ) {}
}
