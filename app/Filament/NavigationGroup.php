<?php

namespace App\Filament;

use Filament\Support\Contracts\HasLabel;

/**
 * Pengelompokan menu, diurutkan dari yang paling sering dibuka.
 *
 * Dasbor & Laporan sengaja TIDAK masuk grup mana pun: keduanya tujuan
 * langsung, bukan sesuatu yang dicari lewat kategori. Menaruhnya dalam grup
 * hanya menambah satu tingkat yang harus dilewati puluhan kali sehari.
 *
 * Tanpa ikon: Filament menolak grup dan itemnya sama-sama berikon, dan ikon
 * per item jauh lebih berguna — itu yang dicari mata saat memindai daftar.
 */
enum NavigationGroup: string implements HasLabel
{
    case Operasional = 'operasional';
    case MasterData = 'master-data';
    case Sistem = 'sistem';

    public function getLabel(): string
    {
        return match ($this) {
            self::Operasional => 'Operasional',
            self::MasterData => 'Data Master',
            self::Sistem => 'Sistem',
        };
    }
}
