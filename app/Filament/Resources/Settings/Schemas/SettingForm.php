<?php

namespace App\Filament\Resources\Settings\Schemas;

use App\Domain\Settings\SettingType;
use App\Models\Setting;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
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

                    // Saklar dan jam punya kontrolnya sendiri. Memaksa keduanya
                    // lewat kotak teks berarti pemilik outlet mengetik "1" atau
                    // "22:00" dengan tangan — dan satu salah ketik di jam tutup
                    // mematikan pemesanan tanpa ada yang tahu sebabnya.
                    Toggle::make('value.value')
                        ->label('Aktif')
                        ->helperText('Nonaktifkan untuk menutup pemesanan seketika, berapa pun jamnya.')
                        ->visible(fn (?Setting $record): bool => $record?->key?->type() === SettingType::Toggle)
                        ->dehydrateStateUsing(fn ($state): int => $state ? 1 : 0),

                    TimePicker::make('value.value')
                        ->label('Jam')
                        ->seconds(false)
                        ->format('H:i')
                        ->displayFormat('H:i')
                        // Boleh kosong: jam istirahat kosong = tidak ada
                        // istirahat, dan jam buka = tutup berarti 24 jam.
                        ->helperText(fn (?Setting $record): ?string => $record?->key?->description())
                        ->visible(fn (?Setting $record): bool => $record?->key?->type() === SettingType::Time),

                    TextInput::make('value.value')
                        ->label('Nilai')
                        ->visible(fn (?Setting $record): bool => ! in_array(
                            $record?->key?->type(),
                            [SettingType::Toggle, SettingType::Time],
                            true,
                        ))
                        ->required()
                        ->suffix(fn (?Setting $record): ?string => $record?->key?->type()->suffix())
                        // Menit & rupiah = numerik. minValue menit 1 (0 menit
                        // dulu membuat pembagian pembulatan billing melempar
                        // DivisionByZeroError); rupiah boleh 0 (= biaya dimatikan).
                        ->numeric(fn (?Setting $record): bool => (bool) $record?->key?->type()->isNumeric())
                        ->minValue(fn (?Setting $record): ?int => match ($record?->key?->type()) {
                            SettingType::Minutes => 1,
                            SettingType::Rupiah => 0,
                            default => null,
                        })
                        ->maxValue(fn (?Setting $record): ?int => match ($record?->key?->type()) {
                            SettingType::Minutes => 1440,
                            SettingType::Rupiah => 100_000,
                            default => null,
                        })
                        ->maxLength(fn (?Setting $record): ?int => $record?->key?->type() === SettingType::Text ? 191 : null),
                ]),
        ]);
    }
}
