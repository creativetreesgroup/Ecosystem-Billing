<?php

namespace App\Filament\Resources\Discounts\Tables;

use App\Domain\Billing\Rupiah;
use App\Domain\Discounts\DiscountSource;
use App\Domain\Discounts\DiscountType;
use App\Models\Discount;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class DiscountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('code')
                    ->icon(Heroicon::OutlinedHashtag)
                    ->label('Kode')
                    ->fontFamily('mono')
                    ->searchable()
                    ->placeholder('— (promo)')
                    ->copyable(),
                TextColumn::make('name')
                    ->icon(Heroicon::OutlinedTag)
                    ->label('Nama')
                    ->searchable(),
                TextColumn::make('source')
                    ->label('Sumber')
                    ->badge(),
                TextColumn::make('value')
                    ->icon(Heroicon::OutlinedReceiptPercent)
                    ->label('Potongan')
                    ->formatStateUsing(fn (Discount $record): string => $record->type === DiscountType::Percentage
                        ? $record->value.'%'
                        : Rupiah::format($record->value)),
                // Pemakaian: dihitung dari baris redemption — sumber kebenaran kuota.
                TextColumn::make('redemptions_count')
                    ->label('Terpakai')
                    ->counts('redemptions')
                    ->badge()
                    ->formatStateUsing(fn (int $state, Discount $record): string => $record->max_uses
                        ? "{$state} / {$record->max_uses}"
                        : (string) $state),
                TextColumn::make('ends_at')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->label('Berakhir')
                    ->dateTime('d M Y H:i', timezone: config('app.display_timezone'))
                    ->placeholder('Tanpa batas')
                    ->visibleFrom('lg'),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->label('Sumber')
                    ->options(DiscountSource::class),
                TernaryFilter::make('is_active')
                    ->label('Status'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            // Tanpa hapus: diskon yang sudah terpakai memegang jejak redemption
            // (rekonsiliasi & laporan). Nonaktifkan lewat toggle Aktif sebagai
            // gantinya — sama seperti member & unit.
            ->toolbarActions([]);
    }
}
