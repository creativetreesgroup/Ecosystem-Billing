<?php

namespace App\Filament\Resources\MenuCategories\Pages;

use App\Filament\Concerns\DeletesFromFormFooter;
use App\Filament\Resources\MenuCategories\MenuCategoryResource;
use Filament\Resources\Pages\EditRecord;

class EditMenuCategory extends EditRecord
{
    use DeletesFromFormFooter;

    protected static string $resource = MenuCategoryResource::class;
}
