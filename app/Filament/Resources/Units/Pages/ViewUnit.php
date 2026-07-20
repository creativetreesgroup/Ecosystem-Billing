<?php

namespace App\Filament\Resources\Units\Pages;

use App\Domain\Billing\Rupiah;
use App\Domain\Devices\DeviceAlertStatus;
use App\Domain\Sessions\SessionStatus;
use App\Filament\Resources\Units\UnitResource;
use App\Models\Unit;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;

/**
 * Halaman ini menjawab satu pertanyaan yang sering muncul saat ada keluhan:
 * "unit ini sebenarnya kenapa?"
 *
 * Isinya sengaja bukan pengulangan form. Yang ditampilkan adalah hal-hal yang
 * TIDAK terlihat di daftar maupun di form: apakah perangkatnya benar-benar
 * terpasang, berapa banyak yang sudah dihasilkan unit ini, dan apakah ada
 * alert yang belum ditangani.
 */
class ViewUnit extends ViewRecord
{
    protected static string $resource = UnitResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Keadaan sekarang')
                ->icon(Heroicon::OutlinedSignal)
                ->columns(['md' => 3])
                ->schema([
                    TextEntry::make('power_state')
                        ->label('Status TV')
                        ->badge(),
                    TextEntry::make('control_driver')
                        ->label('Driver kontrol')
                        ->badge(),
                    // Pertanyaan pertama saat TV tidak merespons: perangkatnya
                    // memang sudah dipasangkan atau belum? Di daftar unit ini
                    // tidak terlihat sama sekali.
                    TextEntry::make('perangkat')
                        ->label('Perangkat terpasang')
                        ->state(fn (Unit $record): string => $record->control_ref ?? 'Belum dipasangkan')
                        ->color(fn (Unit $record): string => $record->control_ref ? 'success' : 'warning')
                        ->icon(fn (Unit $record) => $record->control_ref
                            ? Heroicon::OutlinedCheckCircle
                            : Heroicon::OutlinedExclamationTriangle),
                    TextEntry::make('tv_mac')
                        ->label('MAC (Wake-on-LAN)')
                        ->placeholder('Belum diisi — TV tidak bisa dibangunkan dari standby')
                        ->copyable(),
                    TextEntry::make('last_seen_at')
                        ->label('Terakhir terlihat')
                        ->since()
                        ->placeholder('Belum pernah'),
                    TextEntry::make('alert_terbuka')
                        ->label('Alert belum ditangani')
                        ->state(fn (Unit $record): int => $record->deviceAlerts()->where('status', DeviceAlertStatus::Open)->count())
                        ->badge()
                        ->color(fn ($state): string => $state > 0 ? 'danger' : 'gray'),
                ]),

            Section::make('Sesi berjalan')
                ->icon(Heroicon::OutlinedPlayCircle)
                ->columns(['md' => 3])
                ->visible(fn (Unit $record): bool => $record->activeSession !== null)
                ->schema([
                    TextEntry::make('activeSession.customer_name')
                        ->label('Pelanggan')
                        ->placeholder('Tanpa nama'),
                    TextEntry::make('activeSession.type')
                        ->label('Tipe')
                        ->badge(),
                    TextEntry::make('activeSession.started_at')
                        ->label('Mulai')
                        ->dateTime('d M Y H:i', timezone: config('app.display_timezone')),
                ]),

            // Angka-angka ini yang menjawab "unit ini layak dipertahankan atau
            // tidak" — pertanyaan yang selama ini hanya bisa dijawab dengan
            // menyaring Riwayat Sesi secara manual.
            Section::make('Rekam jejak')
                ->icon(Heroicon::OutlinedChartBar)
                ->columns(['md' => 3])
                ->schema([
                    TextEntry::make('total_sesi')
                        ->label('Sesi selesai')
                        ->state(fn (Unit $record): int => $record->rentalSessions()->where('status', SessionStatus::Completed)->count())
                        ->size(TextSize::Large)
                        ->weight(FontWeight::Bold),
                    TextEntry::make('total_pendapatan')
                        ->label('Total pendapatan')
                        ->state(fn (Unit $record): string => Rupiah::format(
                            (int) $record->rentalSessions()->where('status', SessionStatus::Completed)->sum('total_amount')
                        ))
                        ->size(TextSize::Large)
                        ->weight(FontWeight::Bold)
                        ->color('success'),
                    TextEntry::make('sesi_dibatalkan')
                        ->label('Sesi dibatalkan')
                        ->state(fn (Unit $record): int => $record->rentalSessions()->where('status', SessionStatus::Voided)->count())
                        ->badge()
                        ->color(fn ($state): string => $state > 0 ? 'warning' : 'gray'),
                ]),

            Grid::make(1)->schema([
                Section::make('Catatan')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->collapsed()
                    ->visible(fn (Unit $record): bool => filled($record->notes))
                    ->schema([
                        TextEntry::make('notes')->hiddenLabel(),
                    ]),
            ]),
        ]);
    }
}
