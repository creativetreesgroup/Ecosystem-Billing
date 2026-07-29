<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Domain\Billing\Rupiah;
use App\Filament\Resources\Customers\Actions\AdjustBalanceAction;
use App\Filament\Resources\Customers\Actions\CashTopUpAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->icon(Heroicon::OutlinedUser)
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->icon(Heroicon::OutlinedDevicePhoneMobile)
                    ->label('Nomor WA')
                    ->searchable()
                    ->copyable(),
                // Nomor kartu dicari kasir saat member menunjukkannya; bisa
                // disalin utuh di sini karena ini panel internal, bukan layar
                // pelanggan.
                TextColumn::make('card_number')
                    ->icon(Heroicon::OutlinedCreditCard)
                    ->label('Nomor kartu')
                    ->visibleFrom('lg')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->color('gray'),
                TextColumn::make('balance')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->label('Saldo')
                    ->sortable()
                    ->formatStateUsing(fn (int $state): string => Rupiah::format($state))
                    // Merah bila minus (utang Open Play yang belum dilunasi).
                    ->color(fn (int $state): string => $state < 0 ? 'danger' : 'success')
                    ->weight('bold'),
                TextColumn::make('wallet_transactions_count')
                    ->label('Transaksi')
                    ->visibleFrom('lg')
                    ->badge()
                    ->color('gray'),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
                TextColumn::make('last_seen_at')
                    ->icon(Heroicon::OutlinedClock)
                    ->label('Terakhir main')
                    ->visibleFrom('md')
                    ->since()
                    ->placeholder('Belum pernah'),
                TextColumn::make('created_at')
                    ->icon(Heroicon::OutlinedCalendar)
                    ->label('Terdaftar')
                    ->visibleFrom('xl')
                    ->date('d M Y'),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status akun'),
                TernaryFilter::make('has_debt')
                    ->label('Saldo minus')
                    ->queries(
                        true: fn ($query) => $query->where('balance', '<', 0),
                        false: fn ($query) => $query->where('balance', '>=', 0),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordActions([
                CashTopUpAction::make(),
                AdjustBalanceAction::make(),
                ViewAction::make(),
                EditAction::make(),
            ])
            // Tanpa bulk delete: member tidak boleh dihapus (jejak saldo).
            ->toolbarActions([]);
    }
}
