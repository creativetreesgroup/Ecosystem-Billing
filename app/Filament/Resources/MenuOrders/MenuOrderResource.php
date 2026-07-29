<?php

namespace App\Filament\Resources\MenuOrders;

use App\Domain\Menu\MenuOrderStatus;
use App\Filament\NavigationGroup;
use App\Filament\Resources\MenuOrders\Pages\ListMenuOrders;
use App\Filament\Resources\MenuOrders\Tables\MenuOrdersTable;
use App\Models\MenuOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class MenuOrderResource extends Resource
{
    protected static ?string $model = MenuOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Operasional;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Pesanan';

    protected static ?string $modelLabel = 'pesanan';

    protected static ?string $pluralModelLabel = 'pesanan';

    public static function table(Table $table): Table
    {
        return MenuOrdersTable::configure($table);
    }

    /** Jumlah pesanan yang masih harus dikerjakan — terlihat tanpa membuka halaman. */
    public static function getNavigationBadge(): ?string
    {
        $open = static::getModel()::query()
            ->whereIn('status', [MenuOrderStatus::Placed, MenuOrderStatus::Preparing])
            ->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMenuOrders::route('/'),
        ];
    }
}
