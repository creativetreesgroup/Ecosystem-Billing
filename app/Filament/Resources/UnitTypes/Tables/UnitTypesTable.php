<?php

namespace App\Filament\Resources\UnitTypes\Tables;

use App\Domain\Billing\Rupiah;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
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
                    ->icon(Heroicon::OutlinedBuildingStorefront)
                    ->visibleFrom('md')
                    ->label('Outlet')
                    ->searchable(),
                TextColumn::make('name')
                    ->icon(Heroicon::OutlinedTag)
                    ->label('Nama tipe')
                    ->searchable(),
                TextColumn::make('hourly_rate')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->label('Tarif per jam')
                    ->formatStateUsing(fn (?int $state) => $state === null ? null : Rupiah::format($state))
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->icon(Heroicon::OutlinedBars3BottomLeft)
                    ->visibleFrom('lg')
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
