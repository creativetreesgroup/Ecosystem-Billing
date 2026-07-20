<?php

namespace App\Filament\Resources\Settings\Schemas;

use App\Domain\Settings\SettingType;
use App\Models\Setting;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Form ini TIDAK tahu apa-apa tentang pengaturan tertentu.
 *
 * Versi sebelumnya dipaku ke `value.minutes`, sehingga pengaturan yang bukan
 * menit tidak bisa diedit sama sekali. Semua sifatnya kini datang dari
 * SettingKey — menambah pengaturan baru cukup menambah satu case di enum,
 * tanpa menyentuh berkas ini.
 */
class SettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(fn (?Setting $record): string => $record?->label() ?? 'Pengaturan')
                ->description(fn (?Setting $record): ?string => $record?->key?->description())
                ->icon(fn (?Setting $record) => $record?->key?->getIcon())
                ->schema([
                    TextEntry::make('key')
                        ->label('Kunci sistem')
                        ->state(fn (?Setting $record): ?string => $record?->key?->value)
                        ->color('gray')
                        ->copyable(),

                    TextInput::make('value.value')
                        ->label('Nilai')
                        ->required()
                        ->suffix(fn (?Setting $record): ?string => $record?->key?->type()->suffix())
                        // minValue(1), bukan 0: pembulatan billing 0 menit dulu
                        // membuat setiap penutupan sesi open play melempar
                        // DivisionByZeroError sehingga sesinya tidak bisa
                        // diselesaikan sama sekali.
                        ->numeric(fn (?Setting $record): bool => $record?->key?->type() === SettingType::Minutes)
                        ->minValue(fn (?Setting $record): ?int => $record?->key?->type() === SettingType::Minutes ? 1 : null)
                        ->maxValue(fn (?Setting $record): ?int => $record?->key?->type() === SettingType::Minutes ? 1440 : null)
                        ->maxLength(fn (?Setting $record): ?int => $record?->key?->type() === SettingType::Text ? 191 : null),
                ]),
        ]);
    }
}
