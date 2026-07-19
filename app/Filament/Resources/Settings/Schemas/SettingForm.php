<?php

namespace App\Filament\Resources\Settings\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')
                    ->label('Kunci')
                    ->disabled()
                    ->dehydrated(),
                TextInput::make('value.minutes')
                    ->label('Nilai (menit)')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
            ]);
    }
}
