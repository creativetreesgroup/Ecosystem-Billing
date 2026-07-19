<?php

namespace App\Filament\Resources\Integrations\Schemas;

use App\Models\Integration;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class IntegrationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(fn (?Integration $record): string => $record?->key?->getLabel() ?? 'Integrasi')
                    ->description(fn (?Integration $record): ?string => $record?->key?->description())
                    ->icon(fn (?Integration $record) => $record?->key?->getIcon())
                    // ['md' => 2], bukan columns(2) — columns(2) di Filament
                    // berarti ['lg' => 2] dan tablet ikut menumpuk seperti HP.
                    ->columns(['md' => 2])
                    ->schema([
                        TextInput::make('base_url')
                            ->label('Alamat')
                            ->placeholder(fn (?Integration $record): ?string => $record?->key?->baseUrlPlaceholder())
                            ->url()
                            ->helperText('Alamat Home Assistant dari sudut pandang mesin ini. Kalau HA berjalan di mesin lain, gunakan IP-nya — bukan localhost.')
                            ->columnSpanFull(),

                        // Token TIDAK PERNAH dikirim balik ke browser.
                        //
                        // dehydrated hanya saat diisi: mengosongkan kolom ini
                        // berarti "tidak diubah", bukan "hapus tokennya" —
                        // kalau tidak, setiap penyimpanan kecil (mis. mengubah
                        // alamat saja) akan menghapus token diam-diam dan
                        // memutus seluruh kendali TV outlet.
                        TextInput::make('token')
                            ->label('Long-lived access token')
                            ->password()
                            ->revealable(false)
                            ->autocomplete(false)
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                            ->placeholder(fn (?Integration $record): string => $record?->maskedToken() ?? 'Belum diisi')
                            ->helperText('Buat di Home Assistant: klik nama Anda (kiri bawah) → tab Security → Long-lived access tokens → Create Token. Token hanya muncul sekali. Kosongkan bila tidak ingin menggantinya.')
                            ->columnSpanFull(),

                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->helperText('Dimatikan berarti sistem kembali memakai nilai dari .env, bukan berhenti mencoba.')
                            ->default(true),

                        TextEntry::make('verified_at')
                            ->label('Terakhir diuji')
                            ->state(fn (?Integration $record): string => $record?->verified_at
                                ? $record->verified_at->setTimezone(config('app.display_timezone'))->format('d M Y H:i')
                                : 'Belum pernah diuji')
                            ->color(fn (?Integration $record): string => $record?->verified_at ? 'success' : 'gray'),
                    ]),
            ]);
    }
}
