<?php

namespace App\Filament\Resources\Integrations\Pages;

use App\Filament\Resources\Integrations\IntegrationResource;
use Filament\Resources\Pages\ListRecords;

class ListIntegrations extends ListRecords
{
    protected static string $resource = IntegrationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
