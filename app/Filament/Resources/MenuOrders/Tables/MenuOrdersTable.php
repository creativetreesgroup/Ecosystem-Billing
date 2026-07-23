<?php

namespace App\Filament\Resources\MenuOrders\Tables;

use App\Domain\Billing\Rupiah;
use App\Domain\Menu\Actions\AdvanceMenuOrderAction;
use App\Domain\Menu\Actions\CancelMenuOrderAction;
use App\Domain\Menu\MenuOrderStatus;
use App\Models\MenuOrder;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Antrean dapur/counter. Di-poll supaya pesanan baru muncul tanpa staf harus
 * ingat menekan refresh — layar ini ditinggal terbuka sepanjang jam operasional.
 */
class MenuOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->icon(Heroicon::OutlinedCalendar)
                    ->label('Masuk')
                    ->since()
                    ->sortable(),
                TextColumn::make('unit.code')
                    ->icon(Heroicon::OutlinedTv)
                    ->label('Unit')
                    ->badge()
                    ->color('gray')
                    ->searchable(),
                TextColumn::make('customer.name')
                    ->icon(Heroicon::OutlinedUser)
                    ->visibleFrom('md')
                    ->label('Pelanggan')
                    ->searchable(),
                TextColumn::make('summary')
                    ->icon(Heroicon::OutlinedListBullet)
                    ->label('Pesanan')
                    ->state(fn (MenuOrder $record): string => $record->summary())
                    ->wrap(),
                TextColumn::make('total_amount')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->label('Total')
                    ->formatStateUsing(fn (?int $state) => $state === null ? null : Rupiah::format($state))
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(MenuOrderStatus::class),
            ])
            ->recordActions([
                Action::make('advance')
                    ->label(fn (MenuOrder $record): string => $record->status === MenuOrderStatus::Placed ? 'Siapkan' : 'Tandai diantar')
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->color('primary')
                    ->authorize('update')
                    ->visible(fn (MenuOrder $record): bool => $record->status->isOpen())
                    ->action(function (MenuOrder $record): void {
                        $user = auth()->user();
                        assert($user instanceof User);

                        app(AdvanceMenuOrderAction::class)->handle($record, $user);

                        Notification::make()->success()->title('Status pesanan diperbarui.')->send();
                    }),

                Action::make('cancel')
                    ->label('Batalkan')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->authorize('update')
                    ->visible(fn (MenuOrder $record): bool => $record->status->isRefundable())
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan pesanan ini?')
                    ->modalDescription(fn (MenuOrder $record): string => 'Saldo '.Rupiah::format($record->total_amount).' akan dikembalikan ke pelanggan.')
                    ->action(function (MenuOrder $record): void {
                        $user = auth()->user();
                        assert($user instanceof User);

                        app(CancelMenuOrderAction::class)->handle($record, $user);

                        Notification::make()->success()->title('Pesanan dibatalkan, saldo dikembalikan.')->send();
                    }),
            ])
            ->toolbarActions([]);
    }
}
