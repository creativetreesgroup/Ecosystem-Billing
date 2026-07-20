<?php

namespace App\Domain\Sessions;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SessionStatus: string implements HasColor, HasLabel
{
    /**
     * Dipesan lewat kios dan MENUNGGU pembayaran. Belum berjalan, belum
     * menagih waktu, dan sengaja bukan Active: kalau sesi kios sudah aktif
     * sebelum uangnya masuk, siapa pun bisa main berjam-jam lalu pergi dan
     * sistem tidak punya cara apa pun menagihnya.
     */
    case Pending = 'pending';
    case Active = 'active';
    case Completed = 'completed';
    case Voided = 'voided';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu pembayaran',
            self::Active => 'Aktif',
            self::Completed => 'Selesai',
            self::Voided => 'Dibatalkan',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Active => 'success',
            self::Completed => 'gray',
            self::Voided => 'danger',
        };
    }
}
