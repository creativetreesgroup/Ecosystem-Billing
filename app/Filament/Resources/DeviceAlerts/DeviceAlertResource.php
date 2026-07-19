<?php

namespace App\Filament\Resources\DeviceAlerts;

use App\Domain\Devices\DeviceAlertStatus;
use App\Filament\NavigationGroup;
use App\Filament\Resources\DeviceAlerts\Pages\ListDeviceAlerts;
use App\Filament\Resources\DeviceAlerts\Tables\DeviceAlertsTable;
use App\Models\DeviceAlert;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DeviceAlertResource extends Resource
{
    protected static ?string $model = DeviceAlert::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Operasional;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Alert Perangkat';

    protected static ?string $modelLabel = 'alert';

    protected static ?string $pluralModelLabel = 'Alert Perangkat';

    public static function table(Table $table): Table
    {
        return DeviceAlertsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeviceAlerts::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::where('status', DeviceAlertStatus::Open)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }
}
