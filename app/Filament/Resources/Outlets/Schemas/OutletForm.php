<?php

namespace App\Filament\Resources\Outlets\Schemas;

use DateTimeZone;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OutletForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Outlet')
                    ->description('Timezone dipakai menghitung durasi & jadwal sesi rental di outlet ini.')
                    ->columns(['md' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama outlet')
                            ->placeholder('mis. Creative Trees — Cabang Utama')
                            ->required(),
                        // Select dari daftar zona PHP, bukan free text: kolom ini
                        // dipakai hitung waktu sesi, salah ketik = jam sesi meleset.
                        Select::make('timezone')
                            ->label('Timezone')
                            ->options(array_combine(
                                DateTimeZone::listIdentifiers(),
                                DateTimeZone::listIdentifiers(),
                            ))
                            ->searchable()
                            ->required()
                            ->default('Asia/Jakarta'),
                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true),
                    ]),
            ]);
    }
}
