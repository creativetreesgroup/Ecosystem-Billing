<?php

namespace App\Filament\Resources\Packages\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PackageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('unit_type_id')
                    ->label('Tipe unit')
                    ->relationship('unitType', 'name')
                    ->required(),
                TextInput::make('name')
                    ->label('Nama paket')
                    ->required(),
                TextInput::make('duration_minutes')
                    ->label('Durasi (menit)')
                    ->required()
                    ->numeric()
                    ->suffix('menit'),
                TextInput::make('price')
                    ->label('Harga')
                    ->required()
                    ->numeric()
                    ->prefix('Rp'),
                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true)
                    ->required(),
            ]);
    }
}
