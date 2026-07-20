<?php

namespace App\Filament\Resources\DeviceAlerts\Tables;

use App\Domain\Devices\DeviceAlertStatus;
use App\Domain\Devices\DeviceAlertType;
use App\Models\DeviceAlert;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

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
                    ->visibleFrom('md')
                    ->label('Tipe')
                    ->badge(),
                TextColumn::make('message')
                    ->label('Pesan')
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('acknowledgedBy.name')
                    ->visibleFrom('lg')
                    ->label('Ditangani oleh')
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->visibleFrom('md')
                    ->label('Waktu')
                    ->dateTime('d/m/Y H:i', timezone: config('app.display_timezone'))
                    ->sortable(),
            ])
            // Yang belum ditangani lebih dulu, lalu yang terbaru: daftar alert
            // dibuka justru untuk mengerjakannya, bukan membaca arsipnya.
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(DeviceAlertStatus::class)
                    ->default(DeviceAlertStatus::Open->value),
                SelectFilter::make('type')
                    ->label('Jenis')
                    ->options(DeviceAlertType::class),
                SelectFilter::make('unit_id')
                    ->label('Unit')
                    ->relationship('unit', 'code'),
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
            ->toolbarActions([
                BulkActionGroup::make([
                    // Satu gangguan jaringan bisa melahirkan alert untuk semua
                    // unit sekaligus. Menandainya satu per satu berarti membuka
                    // enam dialog untuk satu kejadian yang sama.
                    BulkAction::make('acknowledgeMany')
                        ->label('Tandai ditangani')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription('Menandai alert sebagai sudah ditangani tidak memperbaiki perangkatnya — pastikan sudah dicek fisik.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $jumlah = $records
                                ->filter(fn (DeviceAlert $a) => $a->status === DeviceAlertStatus::Open)
                                ->filter(fn (DeviceAlert $a) => auth()->user()->can('acknowledge', $a))
                                ->each(fn (DeviceAlert $a) => $a->update([
                                    'status' => DeviceAlertStatus::Acknowledged,
                                    'acknowledged_by' => auth()->id(),
                                    'acknowledged_at' => now(),
                                ]))
                                ->count();

                            Notification::make()
                                ->title("{$jumlah} alert ditandai ditangani")
                                ->body($jumlah < $records->count() ? 'Sisanya dilewati: sudah ditangani atau di luar wewenang Anda.' : null)
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }
}
