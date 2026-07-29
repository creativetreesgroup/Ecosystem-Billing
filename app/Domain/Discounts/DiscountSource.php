<?php

namespace App\Domain\Discounts;

use Filament\Support\Contracts\HasLabel;

enum DiscountSource: string implements HasLabel
{
    /** Butuh kode yang diketik pelanggan/kasir. */
    case Voucher = 'voucher';

    /** Berlaku otomatis bila aturannya cocok — tanpa kode. */
    case Promo = 'promo';

    public function getLabel(): string
    {
        return match ($this) {
            self::Voucher => 'Voucher (kode)',
            self::Promo => 'Promo otomatis',
        };
    }
}
