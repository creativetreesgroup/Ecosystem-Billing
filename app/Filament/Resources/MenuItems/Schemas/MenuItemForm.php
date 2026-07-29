<?php

namespace App\Filament\Resources\MenuItems\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MenuItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Item menu')
                    ->description('Yang bisa dipesan pelanggan dari kios. Habis? Matikan "Aktif" — tak perlu dihapus.')
                    ->columns(['md' => 2])
                    ->schema([
                        Select::make('menu_category_id')
                            ->label('Kategori')
                            ->relationship('category', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),
                        TextInput::make('name')
                            ->label('Nama item')
                            ->placeholder('mis. Indomie Goreng')
                            ->required()
                            ->maxLength(191),
                        // minValue(1): kolomnya unsigned, nilai negatif lolos
                        // numeric() lalu menabrak kolom dan melempar 500.
                        // Sekaligus menutup item gratis yang tak disengaja.
                        TextInput::make('price')
                            ->label('Harga')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            ->prefix('Rp'),
                        TextInput::make('sort_order')
                            ->label('Urutan tampil')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->helperText('Nonaktif = tidak muncul di kios (mis. sedang habis).')
                            ->default(true)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
