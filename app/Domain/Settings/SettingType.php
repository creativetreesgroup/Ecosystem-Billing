<?php

namespace App\Domain\Settings;

enum SettingType: string
{
    case Minutes = 'minutes';
    case Text = 'text';
    case Rupiah = 'rupiah';
    case Time = 'time';
    case Toggle = 'toggle';

    public function suffix(): ?string
    {
        return match ($this) {
            self::Minutes => 'menit',
            self::Rupiah => 'rupiah',
            self::Text, self::Time, self::Toggle => null,
        };
    }

    /** Pengaturan angka (menit atau rupiah) dirender sebagai input numerik. */
    public function isNumeric(): bool
    {
        return $this === self::Minutes || $this === self::Rupiah;
    }
}
