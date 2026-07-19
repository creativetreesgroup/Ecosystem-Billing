<?php

namespace App\Filament\Resources\Units\Pages;

use App\Filament\Resources\Units\UnitResource;
use Filament\Resources\Pages\EditRecord;

class EditUnit extends EditRecord
{
    protected static string $resource = UnitResource::class;

    /**
     * Tanpa DeleteAction: rental_sessions menyimpan FK unit_id dengan
     * restrictOnDelete, jadi menghapus unit yang punya riwayat sesi melempar
     * QueryException mentah ke layar. Nonaktifkan lewat toggle "Aktif".
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
