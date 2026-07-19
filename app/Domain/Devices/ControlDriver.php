<?php

namespace App\Domain\Devices;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ControlDriver: string implements HasColor, HasLabel
{
    case HomeAssistant = 'home_assistant';
    case Tasmota = 'tasmota';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::HomeAssistant => 'Home Assistant',
            self::Tasmota => 'Tasmota',
            self::Manual => 'Manual',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::HomeAssistant, self::Tasmota => 'info',
            self::Manual => 'gray',
        };
    }
}
