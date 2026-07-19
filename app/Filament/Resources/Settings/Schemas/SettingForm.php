<?php

namespace App\Filament\Resources\Settings\Schemas;

use App\Models\Setting;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SettingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Pengaturan')
                    ->description('Berlaku langsung ke seluruh outlet begitu disimpan.')
                    // ['md' => 2], bukan columns(2) — columns(2) di Filament
                    // berarti ['lg' => 2] dan tablet ikut menumpuk seperti HP.
                    ->columns(['md' => 2])
                    ->schema([
                        TextInput::make('key')
                            ->label('Pengaturan')
                            ->formatStateUsing(fn (?Setting $record): ?string => $record?->label())
                            ->helperText(fn (?Setting $record): ?string => $record?->key)
                            ->disabled(),
                        // minValue(1), bukan 0: pembulatan billing 0 menit dulu membuat
                        // setiap penutupan sesi open play melempar DivisionByZeroError
                        // sehingga sesinya tidak bisa diselesaikan sama sekali.
                        TextInput::make('value.minutes')
                            ->label('Nilai')
                            ->suffix('menit')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(1440)
                            ->required(),
                    ]),
            ]);
    }
}
