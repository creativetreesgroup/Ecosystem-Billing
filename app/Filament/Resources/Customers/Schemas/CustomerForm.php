<?php

namespace App\Filament\Resources\Customers\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

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
                TextInput::make('name')
                    ->label('Nama')
                    ->required()
                    ->maxLength(60),
                // Reset PIN mengikuti pola field password: kosong saat dibuka,
                // dan HANYA menimpa PIN lama bila diisi (dehydrated-when-filled).
                // Tanpa keduanya, membuka form lalu menyimpan akan menimpa PIN
                // dengan hash lama yang di-hash ulang — pelanggan langsung
                // terkunci. Cast `hashed` di model yang meng-hash nilai barunya.
                TextInput::make('pin_hash')
                    ->label('Reset PIN')
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
                    ->default(true),
            ]);
    }
}
