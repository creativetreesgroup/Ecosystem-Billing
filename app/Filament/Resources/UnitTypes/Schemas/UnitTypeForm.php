<?php

namespace App\Filament\Resources\UnitTypes\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UnitTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tipe unit')
                    ->description('Tarif per jam di sini yang dipakai menghitung tagihan Open Play.')
                    // ['md' => 2], bukan columns(2) — columns(2) di Filament
                    // berarti ['lg' => 2] dan tablet ikut menumpuk seperti HP.
                    ->columns(['md' => 2])
                    ->schema([
                        Select::make('outlet_id')
                            ->label('Outlet')
                            ->relationship('outlet', 'name')
                            ->required(),
                        TextInput::make('name')
                            ->label('Nama tipe')
                            ->placeholder('mis. Non-VIP')
                            ->required(),
                        TextInput::make('hourly_rate')
                            ->label('Tarif per jam')
                            ->required()
                            ->numeric()
                            ->prefix('Rp'),
                        TextInput::make('sort_order')
                            ->label('Urutan tampil')
                            ->helperText('Makin kecil, makin awal muncul di daftar.')
                            ->required()
                            ->numeric()
                            ->default(0),
                    ]),
            ]);
    }
}
