<?php

namespace App\Filament\Resources\Integrations;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Integrations\Pages\EditIntegration;
use App\Filament\Resources\Integrations\Pages\ListIntegrations;
use App\Filament\Resources\Integrations\Schemas\IntegrationForm;
use App\Filament\Resources\Integrations\Tables\IntegrationsTable;
use App\Models\Integration;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class IntegrationResource extends Resource
{
    protected static ?string $model = Integration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Sistem;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Integrasi';

    protected static ?string $modelLabel = 'integrasi';

    protected static ?string $pluralModelLabel = 'integrasi';

    public static function form(Schema $schema): Schema
    {
        return IntegrationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IntegrationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIntegrations::route('/'),
            'edit' => EditIntegration::route('/{record}/edit'),
        ];
    }

    /**
     * Daftar integrasi ditentukan sistem (IntegrationKey) — owner mengisi
     * kredensialnya, bukan menambah baris yang tidak akan pernah dibaca driver.
     */
    public static function canCreate(): bool
    {
        return false;
    }
}
