<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    // Tanpa tombol "Buat": member mendaftar sendiri di kios.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
