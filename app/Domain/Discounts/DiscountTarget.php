<?php

namespace App\Domain\Discounts;

use Filament\Support\Contracts\HasLabel;

/**
 * Permukaan yang boleh didiskon. Sengaja terbatas: paket & top-up dipotong dari
 * nominal tetap; Open Play hanya persen atas tagihan akhir (potongan tetap pada
 * tagihan per-menit yang berubah-ubah tidak masuk akal).
 */
enum DiscountTarget: string implements HasLabel
{
    case Package = 'package';
    case TopUp = 'topup';
    case OpenPlay = 'open_play';

    public function getLabel(): string
    {
        return match ($this) {
            self::Package => 'Paket main',
            self::TopUp => 'Isi saldo',
            self::OpenPlay => 'Open Play',
        };
    }
}
