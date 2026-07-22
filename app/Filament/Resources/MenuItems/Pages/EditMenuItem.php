<?php

namespace App\Filament\Resources\MenuItems\Pages;

use App\Filament\Concerns\DeletesFromFormFooter;
use App\Filament\Resources\MenuItems\MenuItemResource;
use Filament\Resources\Pages\EditRecord;

class EditMenuItem extends EditRecord
{
    use DeletesFromFormFooter;

    protected static string $resource = MenuItemResource::class;
}
