<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Tanpa DeleteAction — akun tidak pernah dihapus karena rental_sessions
     * menyimpan FK ke user dengan restrictOnDelete (lihat UserPolicy::delete()).
     * Nonaktifkan lewat toggle "Aktif" sebagai gantinya.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
