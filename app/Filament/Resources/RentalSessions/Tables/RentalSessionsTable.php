<?php

namespace App\Filament\Resources\RentalSessions\Tables;

use App\Domain\Billing\Rupiah;
use App\Domain\Sessions\Actions\VoidSessionAction;
use App\Domain\Sessions\SessionStatus;
use App\Models\RentalSession;
use App\Models\Unit;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RentalSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('unit.code')
                    ->label('Unit')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('openedBy.name')
                    ->label('Kasir'),
                TextColumn::make('customer_name')
                    ->label('Pelanggan')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('type')
                    ->label('Tipe')
                    ->badge(),
                TextColumn::make('package.name')
                    ->label('Paket')
                    ->placeholder('-'),
                TextColumn::make('started_at')
                    ->label('Mulai')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('ended_at')
                    ->label('Selesai')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->formatStateUsing(fn (?int $state) => $state === null ? null : Rupiah::format($state))
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('payment_method')
                    ->label('Pembayaran')
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('voidedBy.name')
                    ->label('Di-void oleh')
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('unit_id')
                    ->label('Unit')
                    ->options(fn () => Unit::query()->pluck('code', 'id')),
                SelectFilter::make('opened_by')
                    ->label('Kasir')
                    ->options(fn () => User::query()->pluck('name', 'id')),
                Filter::make('started_at')
                    ->schema([
                        DatePicker::make('from')->label('Dari tanggal'),
                        DatePicker::make('until')->label('Sampai tanggal'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('started_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('started_at', '<=', $date));
                    }),
            ])
            ->recordActions([
                Action::make('void')
                    ->label('Void')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->authorize(fn (RentalSession $record) => auth()->user()->can('void', $record))
                    ->visible(fn (RentalSession $record) => $record->status !== SessionStatus::Voided)
                    ->schema([
                        Textarea::make('reason')
                            ->label('Alasan void')
                            ->required(),
                    ])
                    ->action(function (RentalSession $record, array $data): void {
                        app(VoidSessionAction::class)->handle($record, auth()->user(), $data['reason']);

                        Notification::make()
                            ->title('Sesi di-void')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([]);
    }
}
