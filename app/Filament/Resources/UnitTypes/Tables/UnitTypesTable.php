<?php

namespace App\Filament\Resources\UnitTypes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UnitTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('outlet.name')
                    ->label('Outlet')
                    ->searchable(),
                TextColumn::make('name')
                    ->label('Nama tipe')
                    ->searchable(),
                TextColumn::make('hourly_rate')
                    ->label('Tarif per jam')
                    ->money('IDR', locale: 'id')
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
