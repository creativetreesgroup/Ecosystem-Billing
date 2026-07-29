<?php

namespace App\Filament\Resources\Users\Tables;

use App\Domain\Users\PermissionGroup;
use App\Models\Role;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class UsersTable
{
    /**
     * Warna lencana per nama peran, dihitung SEKALI.
     *
     * Versi pertama menanyakan grup peran di dalam closure warna, yaitu dua
     * query untuk setiap lencana di setiap baris — tak terasa dengan lima
     * pengguna, dan menjadi ratusan query begitu daftarnya bertambah.
     *
     * @return array<string, string>
     */
    private static function groupColours(): array
    {
        return once(fn (): array => Role::query()
            ->pluck('group', 'name')
            ->map(fn (?PermissionGroup $group): string => $group?->getColor() ?? 'danger')
            ->all());
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->icon(Heroicon::OutlinedUser)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->visibleFrom('md')
                    ->label('Email')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->searchable()
                    ->copyable(),
                // Peran kini bisa lebih dari satu, dan warnanya mengikuti
                // departemen — dari jarak satu meter itu yang terbaca lebih
                // dulu daripada namanya.
                TextColumn::make('roles.name')
                    ->label('Peran')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => Str::of((string) $state)->replace(['_', '-'], ' ')->title()->toString())
                    ->color(fn ($state): string => self::groupColours()[$state] ?? 'danger')
                    ->separator(','),
                IconColumn::make('is_active')
                    ->visibleFrom('md')
                    ->label('Aktif')
                    ->boolean(),
                TextColumn::make('opened_sessions_count')
                    ->visibleFrom('lg')
                    ->label('Sesi dibuka')
                    ->counts('openedSessions')
                    ->badge()
                    ->color('gray'),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->label('Peran')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload(),
                TernaryFilter::make('is_active')
                    ->label('Aktif'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            // Tanpa bulk delete: akun tidak boleh dihapus sama sekali
            // (lihat UserPolicy::delete()) — dinonaktifkan lewat is_active.
            ->toolbarActions([]);
    }
}
