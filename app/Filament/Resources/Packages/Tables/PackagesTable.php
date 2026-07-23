<?php

namespace App\Filament\Resources\Packages\Tables;

use App\Domain\Billing\Rupiah;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PackagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('unitType.name')
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->visibleFrom('md')
                    ->label('Tipe unit')
                    ->searchable(),
                TextColumn::make('name')
                    ->icon(Heroicon::OutlinedTag)
                    ->label('Nama paket')
                    ->searchable(),
                TextColumn::make('duration_minutes')
                    ->icon(Heroicon::OutlinedClock)
                    ->label('Durasi')
                    ->numeric()
                    ->suffix(' menit')
                    ->sortable(),
                TextColumn::make('price')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->label('Harga')
                    ->formatStateUsing(fn (?int $state) => $state === null ? null : Rupiah::format($state))
                    ->sortable(),
                IconColumn::make('is_active')
                    ->visibleFrom('md')
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
