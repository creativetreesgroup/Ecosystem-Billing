<?php

namespace App\Domain\Menu;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Keadaan layanan pesan-antar makanan saat ini.
 *
 * Dibedakan menjadi empat, bukan sekadar buka/tutup, karena yang perlu
 * dilakukan pelanggan berbeda di tiap keadaan: istirahat berarti "tunggu
 * sebentar", tutup berarti "datang lagi besok", dimatikan berarti "tanya
 * kasir". Satu boolean memaksa ketiganya jadi kalimat yang sama.
 */
enum MenuServiceStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Break = 'break';
    case Closed = 'closed';
    case Disabled = 'disabled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Buka',
            self::Break => 'Sedang istirahat',
            self::Closed => 'Tutup',
            self::Disabled => 'Dimatikan',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'success',
            self::Break => 'warning',
            self::Closed, self::Disabled => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
