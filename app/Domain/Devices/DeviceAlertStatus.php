<?php

namespace App\Domain\Devices;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DeviceAlertStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Belum ditangani',
            self::Acknowledged => 'Sudah ditangani',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::Acknowledged => 'success',
        };
    }
}
