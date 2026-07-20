<?php

namespace App\Domain\Devices;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DeviceAlertType: string implements HasColor, HasLabel
{
    case PowerOffFailed = 'power_off_failed';
    case PowerOnFailed = 'power_on_failed';
    case DeviceOffline = 'device_offline';
    case StateMismatch = 'state_mismatch';

    public function getLabel(): string
    {
        return match ($this) {
            self::PowerOffFailed => 'TV gagal dimatikan',
            self::PowerOnFailed => 'TV gagal dinyalakan',
            self::DeviceOffline => 'Perangkat offline',
            self::StateMismatch => 'Status tidak cocok',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PowerOffFailed, self::PowerOnFailed => 'danger',
            self::DeviceOffline, self::StateMismatch => 'warning',
        };
    }
}
