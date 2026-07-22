<?php

namespace App\Filament\Resources\MenuCategories\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MenuCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Kategori menu')
                    ->description('Pengelompokan jajanan di layar kios — mis. Snack, Makanan, Minuman.')
                    ->columns(['md' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama kategori')
                            ->placeholder('mis. Snack')
                            ->required()
                            ->maxLength(191),
                        TextInput::make('sort_order')
                            ->label('Urutan tampil')
                            ->helperText('Angka kecil tampil lebih dulu.')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->helperText('Kategori nonaktif tidak muncul di kios.')
                            ->default(true)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
