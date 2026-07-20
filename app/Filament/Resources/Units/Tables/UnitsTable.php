<?php

namespace App\Filament\Resources\Units\Tables;

use App\Domain\Devices\ControlDriver;
use App\Domain\Devices\DeviceManager;
use App\Domain\Devices\PowerState;
use App\Filament\Resources\Units\UnitResource;
use App\Models\Unit;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

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
                    ->visibleFrom('md')
                    ->label('Driver')
                    ->badge(),
                TextColumn::make('power_state')
                    ->label('Status TV')
                    ->badge(),
                TextColumn::make('last_seen_at')
                    ->visibleFrom('lg')
                    ->label('Terakhir terlihat')
                    ->dateTime('d/m/Y H:i', timezone: config('app.display_timezone'))
                    ->placeholder('-')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->visibleFrom('md')
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
                ViewAction::make(),
                EditAction::make(),
            ])
            ->recordUrl(fn (Unit $record): string => UnitResource::getUrl('view', ['record' => $record]))
            ->toolbarActions([
                BulkActionGroup::make([
                    // Menguji satu per satu berarti membuka enam layar. Saat
                    // outlet baru dipasang, atau saat "TV-nya kenapa ya?",
                    // yang dibutuhkan justru jawaban untuk SEMUANYA sekaligus.
                    BulkAction::make('testConnection')
                        ->label('Uji koneksi TV')
                        ->icon(Heroicon::OutlinedSignal)
                        ->color('gray')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $devices = app(DeviceManager::class);
                            $terhubung = $bermasalah = [];

                            foreach ($records as $unit) {
                                if ($unit->control_driver === ControlDriver::Manual) {
                                    continue;
                                }

                                $state = $devices->attempt($unit, fn ($driver) => $driver->state($unit));

                                $state instanceof PowerState && $state !== PowerState::Unreachable
                                    ? $terhubung[] = $unit->code
                                    : $bermasalah[] = $unit->code;
                            }

                            Notification::make()
                                ->title(count($bermasalah) === 0 ? 'Semua TV menjawab' : count($bermasalah).' TV tidak menjawab')
                                ->body(implode(' · ', array_filter([
                                    $terhubung !== [] ? 'Terhubung: '.implode(', ', $terhubung) : null,
                                    $bermasalah !== [] ? 'Bermasalah: '.implode(', ', $bermasalah) : null,
                                ])) ?: 'Tidak ada unit yang memakai driver perangkat.')
                                ->color($bermasalah === [] ? 'success' : 'warning')
                                ->persistent()
                                ->send();
                        }),

                    // Menonaktifkan, BUKAN menghapus: FK restrictOnDelete di
                    // rental_sessions membuat penghapusan gagal di level DB,
                    // dan riwayat penagihannya memang tidak boleh hilang.
                    BulkAction::make('deactivate')
                        ->label('Nonaktifkan')
                        ->icon(Heroicon::OutlinedEyeSlash)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalDescription('Unit nonaktif hilang dari dasbor kasir. Sesi yang sedang berjalan TIDAK ikut ditutup.')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $jumlah = $records->filter(fn (Unit $u) => $u->is_active)
                                ->each(fn (Unit $u) => $u->update(['is_active' => false]))
                                ->count();

                            Notification::make()->title("{$jumlah} unit dinonaktifkan")->success()->send();
                        }),

                    BulkAction::make('activate')
                        ->label('Aktifkan')
                        ->icon(Heroicon::OutlinedEye)
                        ->color('success')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $jumlah = $records->reject(fn (Unit $u) => $u->is_active)
                                ->each(fn (Unit $u) => $u->update(['is_active' => true]))
                                ->count();

                            Notification::make()->title("{$jumlah} unit diaktifkan")->success()->send();
                        }),
                ]),
            ]);

    }
}
