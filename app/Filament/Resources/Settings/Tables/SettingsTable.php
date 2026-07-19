<?php

namespace App\Filament\Resources\Settings\Tables;

use App\Models\Setting;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->label('Pengaturan')
                    ->state(fn (Setting $record): string => $record->label())
                    ->description(fn (Setting $record): string => $record->key),
                TextColumn::make('value.minutes')
                    ->label('Nilai')
                    ->suffix(' menit')
                    ->badge(),
            ])
            ->filters([])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
