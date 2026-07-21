<?php

namespace App\Filament\Resources\Outlets\Pages;

use App\Filament\Concerns\DeletesFromFormFooter;
use App\Filament\Resources\Outlets\OutletResource;
use Filament\Resources\Pages\EditRecord;

class EditOutlet extends EditRecord
{
    use DeletesFromFormFooter;

    protected static string $resource = OutletResource::class;
}
