<?php

namespace App\Filament\Resources\DeviceAlerts\Tables;

use App\Domain\Devices\DeviceAlertStatus;
use App\Models\DeviceAlert;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DeviceAlertsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('unit.code')
                    ->label('Unit')
                    ->searchable(),
                TextColumn::make('type')
                    ->label('Tipe')
                    ->badge(),
                TextColumn::make('message')
                    ->label('Pesan')
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('acknowledgedBy.name')
                    ->label('Ditangani oleh')
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d/m/Y H:i', timezone: config('app.display_timezone'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(DeviceAlertStatus::class),
            ])
            ->recordActions([
                Action::make('acknowledge')
                    ->label('Tandai ditangani')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->authorize(fn (DeviceAlert $record) => auth()->user()->can('acknowledge', $record))
                    ->visible(fn (DeviceAlert $record) => $record->status === DeviceAlertStatus::Open)
                    ->requiresConfirmation()
                    ->action(function (DeviceAlert $record): void {
                        $record->update([
                            'status' => DeviceAlertStatus::Acknowledged,
                            'acknowledged_by' => auth()->id(),
                            'acknowledged_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Alert ditandai sudah ditangani')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([]);
    }
}
