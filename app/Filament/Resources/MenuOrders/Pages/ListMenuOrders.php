<?php

namespace App\Filament\Resources\MenuOrders\Pages;

use App\Filament\Resources\MenuOrders\MenuOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListMenuOrders extends ListRecords
{
    protected static string $resource = MenuOrderResource::class;
}
