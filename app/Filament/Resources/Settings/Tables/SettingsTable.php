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
                    ->description(fn (Setting $record): string => $record->key->value)
                    ->icon(fn (Setting $record) => $record->key->getIcon()),
                // Suffix ikut tipe pengaturannya, bukan " menit" untuk semua:
                // versi lama menempelkan "menit" bahkan pada nomor rekening.
                TextColumn::make('value.value')
                    ->label('Nilai')
                    ->badge()
                    ->suffix(fn (Setting $record): string => $record->key->type()->suffix() ? ' '.$record->key->type()->suffix() : '')
                    // Pengaturan yang wajib untuk transfer tapi masih kosong
                    // adalah gangguan diam: pelanggan diberi tujuan transfer
                    // yang tidak ada, dan baru ketahuan saat uangnya nyasar.
                    ->placeholder('Belum diisi')
                    ->color(fn (Setting $record): string => blank($record->value['value'] ?? null) ? 'danger' : 'gray'),
            ])
            ->filters([])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
