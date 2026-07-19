<?php

namespace App\Filament\Resources\Packages\Pages;

use App\Filament\Concerns\DeletesFromFormFooter;
use App\Filament\Resources\Packages\PackageResource;
use Filament\Resources\Pages\EditRecord;

class EditPackage extends EditRecord
{
    use DeletesFromFormFooter;

    protected static string $resource = PackageResource::class;
}
