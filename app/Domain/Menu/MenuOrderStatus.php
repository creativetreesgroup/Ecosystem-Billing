<?php

namespace App\Domain\Menu;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Perjalanan satu pesanan jajanan. Uangnya sudah ditarik sejak Placed —
 * status ini soal PENGERJAAN, bukan pembayaran.
 */
enum MenuOrderStatus: string implements HasColor, HasLabel
{
    /** Baru masuk, belum disentuh staf. */
    case Placed = 'placed';
    case Preparing = 'preparing';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Placed => 'Baru',
            self::Preparing => 'Disiapkan',
            self::Delivered => 'Diantar',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Placed => 'warning',
            self::Preparing => 'info',
            self::Delivered => 'success',
            self::Cancelled => 'danger',
        };
    }

    /**
     * Masih boleh dibatalkan (uang dikembalikan). Yang sudah Diantar tidak:
     * makanannya sudah dikonsumsi, mengembalikan uangnya berarti kerugian
     * outlet tanpa jejak alasan.
     */
    public function isRefundable(): bool
    {
        return $this === self::Placed || $this === self::Preparing;
    }

    /** Masih dikerjakan staf (tampil di antrean). */
    public function isOpen(): bool
    {
        return $this === self::Placed || $this === self::Preparing;
    }
}
