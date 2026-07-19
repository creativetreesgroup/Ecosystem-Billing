<?php

namespace App\Domain\Devices;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PowerState: string implements HasColor, HasLabel
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
}
