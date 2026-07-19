<?php

namespace App\Filament\Resources\Settings\Schemas;

use App\Models\Setting;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')
                    ->label('Pengaturan')
                    ->formatStateUsing(fn (?Setting $record): ?string => $record?->label())
                    ->helperText(fn (?Setting $record): ?string => $record?->key)
                    ->disabled(),
                TextInput::make('value.minutes')
                    ->label('Nilai')
                    ->suffix('menit')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
            ]);
    }
}
