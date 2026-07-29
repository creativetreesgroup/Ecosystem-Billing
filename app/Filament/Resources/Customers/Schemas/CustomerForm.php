<?php

namespace App\Filament\Resources\Customers\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Yang boleh diubah di sini: nama, keaktifan, dan reset PIN. Nomor, nomor kartu,
 * dan SALDO tetap tidak ada di form: identitas & uang tidak diubah dengan
 * mengetik biasa — saldo hanya lewat Wallet (aksi Isi Saldo Tunai / Koreksi).
 */
class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Data pelanggan')
                    ->description('Nomor HP, nomor kartu, dan saldo tidak ada di sini — identitas dan uang tidak diubah dengan mengetik biasa.')
                    ->icon(Heroicon::OutlinedIdentification)
                    // ['md' => 2], bukan columns(2): columns(2) di Filament
                    // berarti ['lg' => 2] dan tablet ikut menumpuk seperti HP.
                    ->columns(['md' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama')
                            ->prefixIcon(Heroicon::OutlinedUser)
                            ->required()
                            ->maxLength(60),
                        // Reset PIN mengikuti pola field password: kosong saat dibuka,
                        // dan HANYA menimpa PIN lama bila diisi (dehydrated-when-filled).
                        // Tanpa keduanya, membuka form lalu menyimpan akan menimpa PIN
                        // dengan hash lama yang di-hash ulang — pelanggan langsung
                        // terkunci. Cast `hashed` di model yang meng-hash nilai barunya.
                        TextInput::make('pin_hash')
                            ->label('Reset PIN')
                            ->prefixIcon(Heroicon::OutlinedKey)
                            ->helperText('Isi hanya bila pelanggan lupa PIN. 6 digit angka.')
                            ->password()
                            ->revealable()
                            ->numeric()
                            ->rules(['nullable', 'digits:6'])
                            ->autocomplete('new-password')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->afterStateHydrated(fn (TextInput $component) => $component->state('')),
                        Toggle::make('is_active')
                            ->label('Akun aktif')
                            ->helperText('Akun nonaktif tidak bisa masuk di kios — dipakai sebagai ganti menghapus.')
                            ->default(true)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
