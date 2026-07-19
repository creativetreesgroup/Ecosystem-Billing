<?php

namespace App\Filament\Resources\UnitTypes\Pages;

use App\Filament\Concerns\DeletesFromFormFooter;
use App\Filament\Resources\UnitTypes\UnitTypeResource;
use Filament\Resources\Pages\EditRecord;

class EditUnitType extends EditRecord
{
    use DeletesFromFormFooter;

    protected static string $resource = UnitTypeResource::class;
}
