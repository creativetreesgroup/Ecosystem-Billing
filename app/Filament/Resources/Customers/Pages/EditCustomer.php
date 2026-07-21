<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    // Tanpa hapus: member memegang buku besar; dinonaktifkan, bukan dihapus.
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
