<?php

namespace App\Domain\Settings;

enum SettingType: string
{
    case Minutes = 'minutes';
    case Text = 'text';

    public function suffix(): ?string
    {
        return $this === self::Minutes ? 'menit' : null;
    }
}
