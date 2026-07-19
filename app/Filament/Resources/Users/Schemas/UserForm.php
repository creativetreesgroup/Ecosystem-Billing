<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Outlet;
use App\Models\User;
use App\Models\UserRole;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nama')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                // Owner tidak boleh mengubah peran/keaktifan AKUNNYA SENDIRI:
                // menurunkan diri jadi kasir atau menonaktifkan diri sendiri
                // langsung mengunci keluar dari panel, dan tidak ada jalur
                // pemulihan di dalam aplikasi (harus lewat tinker di server).
                Select::make('role')
                    ->label('Peran')
                    ->options(UserRole::class)
                    ->default(UserRole::Kasir)
                    ->required()
                    ->disabled(fn (?User $record): bool => self::isSelf($record))
                    ->helperText(fn (?User $record): string => self::isSelf($record)
                        ? 'Peran akun sendiri tidak bisa diubah dari sini.'
                        : 'Kasir: operasi sesi & unit. Owner: semuanya, termasuk laporan, void, dan pengaturan.'),
                Select::make('outlet_id')
                    ->label('Outlet')
                    ->relationship('outlet', 'name')
                    ->default(fn () => Outlet::query()->value('id'))
                    ->required(),
                // Wajib saat membuat akun, opsional saat mengubah: kosong
                // berarti kata sandi lama dipertahankan (tidak ikut dikirim
                // ke DB sama sekali). Hashing ditangani cast 'hashed' di model.
                TextInput::make('password')
                    ->label('Kata sandi')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->minLength(8)
                    ->helperText(fn (string $operation): ?string => $operation === 'edit'
                        ? 'Kosongkan bila tidak ingin mengganti kata sandi.'
                        : null),
                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true)
                    ->disabled(fn (?User $record): bool => self::isSelf($record))
                    ->helperText(fn (?User $record): string => self::isSelf($record)
                        ? 'Akun sendiri tidak bisa dinonaktifkan dari sini.'
                        : 'Akun nonaktif langsung ditolak saat login — dipakai sebagai ganti menghapus akun.'),
            ]);
    }

    private static function isSelf(?User $record): bool
    {
        return $record !== null && $record->is(Auth::user());
    }
}
