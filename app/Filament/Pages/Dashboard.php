<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\UnitGridWidget;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Dasbor menyebut widgetnya secara EKSPLISIT.
 *
 * Filament secara default menampilkan semua widget yang ter-discover di
 * app/Filament/Widgets — termasuk widget yang sebenarnya milik halaman
 * Laporan. Membatasinya di sini jauh lebih aman daripada memakai
 * canView() => false di widget itu: canView() dipakai
 * Widget\Concerns\CanAuthorizeAccess untuk abort(403) saat widget di-mount,
 * jadi widget tersebut malah tidak bisa dirender di halaman mana pun.
 */
class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            UnitGridWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
