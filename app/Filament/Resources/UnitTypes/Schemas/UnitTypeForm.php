<?php

namespace App\Filament\Resources\UnitTypes\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UnitTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('outlet_id')
                    ->label('Outlet')
                    ->relationship('outlet', 'name')
                    ->required(),
                TextInput::make('name')
                    ->label('Nama tipe')
                    ->required(),
                TextInput::make('hourly_rate')
                    ->label('Tarif per jam')
                    ->required()
                    ->numeric()
                    ->prefix('Rp'),
                TextInput::make('sort_order')
                    ->label('Urutan tampil')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }
}
