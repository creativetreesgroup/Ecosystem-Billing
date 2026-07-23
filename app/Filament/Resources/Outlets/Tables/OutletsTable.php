<?php

namespace App\Filament\Resources\Outlets\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OutletsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->icon(Heroicon::OutlinedTag)
                    ->label('Nama')
                    ->searchable(),
                TextColumn::make('timezone')
                    ->icon(Heroicon::OutlinedGlobeAsiaAustralia)
                    ->visibleFrom('md')
                    ->searchable(),
                TextColumn::make('units_count')
                    ->icon(Heroicon::OutlinedTv)
                    ->visibleFrom('lg')
                    ->label('Jumlah unit')
                    ->counts('units'),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
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
