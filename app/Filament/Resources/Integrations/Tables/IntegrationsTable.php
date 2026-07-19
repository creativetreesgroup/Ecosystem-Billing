<?php

namespace App\Filament\Resources\Integrations\Tables;

use App\Models\Integration;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IntegrationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->label('Integrasi')
                    ->badge(),
                TextColumn::make('base_url')
                    ->label('Alamat')
                    ->visibleFrom('md')
                    ->placeholder('Memakai nilai .env'),
                // Status kesiapan, BUKAN isi tokennya — token tidak pernah
                // ditampilkan di mana pun, termasuk sebagian.
                TextColumn::make('token_state')
                    ->label('Token')
                    ->badge()
                    ->state(fn (Integration $record): string => filled($record->token) ? 'Tersimpan' : 'Belum diisi')
                    ->color(fn (Integration $record): string => filled($record->token) ? 'success' : 'warning')
                    ->icon(fn (Integration $record) => filled($record->token)
                        ? Heroicon::OutlinedLockClosed
                        : Heroicon::OutlinedExclamationTriangle),
                TextColumn::make('verified_at')
                    ->label('Terakhir diuji')
                    ->visibleFrom('lg')
                    ->dateTime('d/m/Y H:i', timezone: config('app.display_timezone'))
                    ->placeholder('Belum pernah'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([])
            ->paginated(false);
    }
}
