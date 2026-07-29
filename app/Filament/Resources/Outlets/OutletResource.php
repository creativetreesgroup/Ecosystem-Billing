<?php

namespace App\Filament\Resources\Outlets;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Outlets\Pages\CreateOutlet;
use App\Filament\Resources\Outlets\Pages\EditOutlet;
use App\Filament\Resources\Outlets\Pages\ListOutlets;
use App\Filament\Resources\Outlets\Schemas\OutletForm;
use App\Filament\Resources\Outlets\Tables\OutletsTable;
use App\Models\Outlet;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class OutletResource extends Resource
{
    protected static ?string $model = Outlet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::MasterData;

    // Paling jarang disentuh di grup ini (diset sekali saat pasang), jadi
    // paling bawah — Unit/Tipe/Paket yang dibuka harian tetap di atas.
    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Outlet';

    protected static ?string $modelLabel = 'outlet';

    protected static ?string $pluralModelLabel = 'outlet';

    public static function form(Schema $schema): Schema
    {
        return OutletForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OutletsTable::configure($table);
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
            'index' => ListOutlets::route('/'),
            'create' => CreateOutlet::route('/create'),
            'edit' => EditOutlet::route('/{record}/edit'),
        ];
    }
}
