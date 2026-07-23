<?php

namespace App\Filament\Resources\MenuItems\Tables;

use App\Domain\Billing\Rupiah;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MenuItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('category.name')
                    ->icon(Heroicon::OutlinedFolder)
                    ->label('Kategori')
                    ->badge()
                    ->searchable(),
                TextColumn::make('name')
                    ->icon(Heroicon::OutlinedTag)
                    ->label('Item')
                    ->searchable(),
                TextColumn::make('price')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->label('Harga')
                    ->formatStateUsing(fn (?int $state) => $state === null ? null : Rupiah::format($state))
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('menu_category_id')
                    ->label('Kategori')
                    ->relationship('category', 'name'),
            ])
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
