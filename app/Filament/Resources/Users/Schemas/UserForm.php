<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Outlet;
use App\Models\Role;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make()
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Identitas')
                            ->icon(Heroicon::OutlinedUser)
                    // ['md' => 2], bukan columns(2) — columns(2) di Filament
                    // berarti ['lg' => 2] dan tablet ikut menumpuk seperti HP.
                            ->columns(['md' => 2])
                            ->schema([
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
                                        : null)
                                    ->columnSpanFull(),
                            ]),

                        Tab::make('Akses')
                            ->icon(Heroicon::OutlinedKey)
                    // ['md' => 2], bukan columns(2) — columns(2) di Filament
                    // berarti ['lg' => 2] dan tablet ikut menumpuk seperti HP.
                            ->columns(['md' => 2])
                            ->schema([
                                // Owner tidak boleh mengubah peran/keaktifan AKUNNYA SENDIRI:
                                // menurunkan diri jadi kasir atau menonaktifkan diri sendiri
                                // langsung mengunci keluar dari panel, dan tidak ada jalur
                                // pemulihan di dalam aplikasi (harus lewat tinker di server).
                                // Peran datang dari Shield, bukan lagi kolom di
                                // tabel users. Ganda karena seorang manusia boleh
                                // bekerja di lebih dari satu departemen — kasir
                                // lantai lazimnya memegang Operasional sekaligus
                                // Maintenance.
                                Select::make('roles')
                                    ->label('Peran')
                                    ->relationship('roles', 'name')
                                    ->getOptionLabelFromRecordUsing(fn (Role $record): string => $record->label())
                                    ->multiple()
                                    ->preload()
                                    ->searchable()
                                    ->native(false)
                                    ->prefixIcon(Heroicon::OutlinedShieldCheck)
                                    ->required()
                                    ->disabled(fn (?User $record): bool => self::isSelf($record))
                                    ->helperText(fn (?User $record): string => self::isSelf($record)
                                        ? 'Peran akun sendiri tidak bisa diubah dari sini.'
                                        : 'Boleh lebih dari satu.'),
                                Select::make('outlet_id')
                                    ->label('Outlet')
                                    ->prefixIcon(Heroicon::OutlinedBuildingStorefront)
                                    ->native(false)
                                    ->relationship('outlet', 'name')
                                    ->default(fn () => Outlet::query()->value('id'))
                                    ->required(),
                                Toggle::make('is_active')
                                    ->label('Aktif')
                                    ->default(true)
                                    ->disabled(fn (?User $record): bool => self::isSelf($record))
                                    ->helperText(fn (?User $record): string => self::isSelf($record)
                                        ? 'Akun sendiri tidak bisa dinonaktifkan dari sini.'
                                        : 'Akun nonaktif langsung ditolak saat login — dipakai sebagai ganti menghapus akun.')
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    private static function isSelf(?User $record): bool
    {
        return $record !== null && $record->is(Auth::user());
    }
}
