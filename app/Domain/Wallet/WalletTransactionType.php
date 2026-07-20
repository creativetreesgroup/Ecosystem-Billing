<?php

namespace App\Domain\Wallet;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum WalletTransactionType: string implements HasColor, HasLabel
{
    case TopUp = 'topup';
    case Spend = 'spend';
    case Refund = 'refund';

    /**
     * Koreksi manual oleh owner. Sengaja punya jenisnya sendiri, tidak
     * menyamar sebagai top-up: uang yang benar-benar masuk lewat pembayaran
     * dan angka yang diketik manusia tidak boleh terbaca sama di laporan.
     */
    case Adjustment = 'adjustment';

    public function getLabel(): string
    {
        return match ($this) {
            self::TopUp => 'Isi saldo',
            self::Spend => 'Pemakaian',
            self::Refund => 'Pengembalian',
            self::Adjustment => 'Koreksi manual',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::TopUp, self::Refund => 'success',
            self::Spend => 'gray',
            self::Adjustment => 'warning',
        };
    }
}
