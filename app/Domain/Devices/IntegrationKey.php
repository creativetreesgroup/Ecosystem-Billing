<?php

namespace App\Domain\Devices;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Integrasi yang kredensialnya boleh diatur dari panel.
 *
 * Daftarnya ditentukan sistem, bukan diketik operator: setiap kunci di sini
 * punya driver yang benar-benar membacanya. Kunci bebas hanya akan menghasilkan
 * baris yang tidak pernah dipakai siapa pun.
 */
enum IntegrationKey: string implements HasIcon, HasLabel
{
    case HomeAssistant = 'home_assistant';

    public function getLabel(): string
    {
        return match ($this) {
            self::HomeAssistant => 'Home Assistant',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::HomeAssistant => Heroicon::OutlinedHomeModern,
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::HomeAssistant => 'Jembatan ke TV: menyalakan, mematikan, dan membaca status daya tiap unit.',
        };
    }

    /**
     * Contoh alamat, sengaja memakai IP dan bukan localhost: saat panel berjalan
     * di server outlet, Home Assistant hampir selalu ada di mesin lain.
     */
    public function baseUrlPlaceholder(): string
    {
        return match ($this) {
            self::HomeAssistant => 'http://192.168.100.10:8123',
        };
    }
}
