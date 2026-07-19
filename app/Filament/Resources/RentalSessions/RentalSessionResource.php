<?php

namespace App\Filament\Resources\RentalSessions;

use App\Filament\NavigationGroup;
use App\Filament\Resources\RentalSessions\Pages\ListRentalSessions;
use App\Filament\Resources\RentalSessions\Tables\RentalSessionsTable;
use App\Models\RentalSession;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RentalSessionResource extends Resource
{
    protected static ?string $model = RentalSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Operasional;

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Riwayat Sesi';

    protected static ?string $modelLabel = 'sesi';

    protected static ?string $pluralModelLabel = 'Riwayat Sesi';

    public static function table(Table $table): Table
    {
        return RentalSessionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRentalSessions::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
