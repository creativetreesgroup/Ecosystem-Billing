<?php

namespace App\Domain\Settings;

enum SettingType: string
{
    case Minutes = 'minutes';
    case Text = 'text';
    case Rupiah = 'rupiah';

    public function suffix(): ?string
    {
        return match ($this) {
            self::Minutes => 'menit',
            self::Rupiah => 'rupiah',
            self::Text => null,
        };
    }

    /** Pengaturan angka (menit atau rupiah) dirender sebagai input numerik. */
    public function isNumeric(): bool
    {
        return $this === self::Minutes || $this === self::Rupiah;
    }
}
