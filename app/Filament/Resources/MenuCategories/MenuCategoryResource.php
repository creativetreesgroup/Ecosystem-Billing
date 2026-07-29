<?php

namespace App\Filament\Resources\MenuCategories;

use App\Filament\NavigationGroup;
use App\Filament\Resources\MenuCategories\Pages\CreateMenuCategory;
use App\Filament\Resources\MenuCategories\Pages\EditMenuCategory;
use App\Filament\Resources\MenuCategories\Pages\ListMenuCategories;
use App\Filament\Resources\MenuCategories\Schemas\MenuCategoryForm;
use App\Filament\Resources\MenuCategories\Tables\MenuCategoriesTable;
use App\Models\MenuCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class MenuCategoryResource extends Resource
{
    protected static ?string $model = MenuCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::MasterData;

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Kategori Menu';

    protected static ?string $modelLabel = 'kategori menu';

    protected static ?string $pluralModelLabel = 'kategori menu';

    public static function form(Schema $schema): Schema
    {
        return MenuCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MenuCategoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMenuCategories::route('/'),
            'create' => CreateMenuCategory::route('/create'),
            'edit' => EditMenuCategory::route('/{record}/edit'),
        ];
    }
}
