<?php

namespace App\Filament\Resources\UnitTypes;

use App\Filament\Resources\UnitTypes\Pages\CreateUnitType;
use App\Filament\Resources\UnitTypes\Pages\EditUnitType;
use App\Filament\Resources\UnitTypes\Pages\ListUnitTypes;
use App\Filament\Resources\UnitTypes\Schemas\UnitTypeForm;
use App\Filament\Resources\UnitTypes\Tables\UnitTypesTable;
use App\Models\UnitType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class UnitTypeResource extends Resource
{
    protected static ?string $model = UnitType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Tipe Unit';

    protected static ?string $modelLabel = 'tipe unit';

    protected static ?string $pluralModelLabel = 'tipe unit';

    public static function form(Schema $schema): Schema
    {
        return UnitTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UnitTypesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUnitTypes::route('/'),
            'create' => CreateUnitType::route('/create'),
            'edit' => EditUnitType::route('/{record}/edit'),
        ];
    }
}
