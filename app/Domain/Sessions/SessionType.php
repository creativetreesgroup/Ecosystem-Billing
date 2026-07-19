<?php

namespace App\Domain\Sessions;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SessionType: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Package = 'package';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Open Play',
            self::Package => 'Paket',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'info',
            self::Package => 'warning',
        };
    }
}
