<?php

namespace App\Domain\Devices;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum PowerState: string implements HasColor, HasIcon, HasLabel
{
    case On = 'on';
    case Standby = 'standby';
    case Unreachable = 'unreachable';
    case Unknown = 'unknown';

    public function getLabel(): string
    {
        return match ($this) {
            self::On => 'Menyala',
            self::Standby => 'Standby',
            self::Unreachable => 'Tidak terhubung',
            self::Unknown => 'Belum diketahui',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::On => 'success',
            self::Standby => 'gray',
            self::Unreachable => 'danger',
            self::Unknown => 'warning',
        };
    }

    /**
     * Kasir memindai grid unit dari jarak satu meter, di mana warna badge
     * lebih dulu terbaca daripada tulisannya — dan "Tidak terhubung" vs
     * "Belum diketahui" cuma beda semburat merah/kuning. Ikon memberi
     * pembeda kedua yang tidak bergantung pada warna sama sekali.
     */
    public function getIcon(): string|BackedEnum|null
    {
        return match ($this) {
            self::On => Heroicon::OutlinedBolt,
            self::Standby => Heroicon::OutlinedMoon,
            self::Unreachable => Heroicon::OutlinedSignalSlash,
            self::Unknown => Heroicon::OutlinedQuestionMarkCircle,
        };
    }
}
