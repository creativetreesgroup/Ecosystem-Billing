<?php

namespace App\Filament\Resources\Units\Tables;

use App\Domain\Devices\PowerState;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UnitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable(),
                TextColumn::make('unitType.name')
                    ->label('Tipe')
                    ->searchable(),
                TextColumn::make('control_driver')
                    ->label('Driver')
                    ->badge(),
                TextColumn::make('power_state')
                    ->label('Status TV')
                    ->badge()
                    ->color(fn (PowerState $state) => match ($state) {
                        PowerState::On => 'success',
                        PowerState::Standby => 'gray',
                        PowerState::Unreachable => 'danger',
                        PowerState::Unknown => 'warning',
                    }),
                TextColumn::make('last_seen_at')
                    ->label('Terakhir terlihat')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('unit_type_id')
                    ->label('Tipe unit')
                    ->relationship('unitType', 'name'),
                TernaryFilter::make('is_active')
                    ->label('Aktif'),
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
