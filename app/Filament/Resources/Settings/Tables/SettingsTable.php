<?php

namespace App\Filament\Resources\Settings\Tables;

use App\Domain\Settings\SettingType;
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
                    // Saklar ditampilkan sebagai kata, bukan "1"/kosong —
                    // baris yang kosong tadinya tak bisa dibedakan dari
                    // pengaturan yang memang belum diisi.
                    ->formatStateUsing(fn (Setting $record, $state): string => $record->key->type() === SettingType::Toggle
                        ? ((int) $state === 1 ? 'Aktif' : 'Nonaktif')
                        : (string) $state)
                    ->suffix(fn (Setting $record): string => $record->key->type()->suffix() ? ' '.$record->key->type()->suffix() : '')
                    // Pengaturan yang wajib untuk transfer tapi masih kosong
                    // adalah gangguan diam: pelanggan diberi tujuan transfer
                    // yang tidak ada, dan baru ketahuan saat uangnya nyasar.
                    ->placeholder('Belum diisi')
                    ->color(fn (Setting $record): string => match (true) {
                        $record->key->type() === SettingType::Toggle => (int) ($record->value['value'] ?? 0) === 1 ? 'success' : 'danger',
                        // Jam boleh kosong: itu berarti 24 jam / tanpa
                        // istirahat, bukan pengaturan yang terlupakan.
                        $record->key->type() === SettingType::Time => 'gray',
                        blank($record->value['value'] ?? null) => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
