<?php

namespace App\Filament\Resources\Packages\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PackageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Paket')
                    ->description('Paket dibayar di muka saat sesi dimulai, jadi durasi & harga di sini yang langsung tercatat sebagai pendapatan.')
                    // ['md' => 2], bukan columns(2) — columns(2) di Filament
                    // berarti ['lg' => 2] dan tablet ikut menumpuk seperti HP.
                    ->columns(['md' => 2])
                    ->schema([
                        Select::make('unit_type_id')
                            ->label('Tipe unit')
                            ->relationship('unitType', 'name')
                            ->required(),
                        TextInput::make('name')
                            ->label('Nama paket')
                            ->placeholder('mis. Paket 3 Jam')
                            ->required(),
                        TextInput::make('duration_minutes')
                            ->label('Durasi')
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
                            ->helperText('Paket nonaktif tidak muncul saat kasir memulai sesi.')
                            ->default(true)
                            ->required()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
