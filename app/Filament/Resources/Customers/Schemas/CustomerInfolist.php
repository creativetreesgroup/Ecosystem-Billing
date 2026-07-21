<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Domain\Billing\Rupiah;
use App\Models\Customer;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;

/**
 * Halaman member: identitas + saldo dalam sekilas. Untuk menjawab pertanyaan
 * kasir yang paling sering — "orang ini terdaftar tidak, saldonya berapa".
 */
class CustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Member')
                    ->columns(['md' => 2])
                    ->schema([
                        TextEntry::make('name')->label('Nama'),
                        TextEntry::make('phone')->label('Nomor WA')->copyable(),
                        TextEntry::make('card_number')
                            ->label('Nomor kartu')
                            ->fontFamily('mono')
                            ->copyable(),
                        IconEntry::make('is_active')->label('Akun aktif')->boolean(),
                        TextEntry::make('balance')
                            ->label('Saldo')
                            ->formatStateUsing(fn (int $state): string => Rupiah::format($state))
                            ->color(fn (int $state): string => $state < 0 ? 'danger' : 'success')
                            ->weight('bold')
                            ->size(TextSize::Large),
                        TextEntry::make('ledger')
                            ->label('Buku besar')
                            ->state(fn (Customer $record): string => Rupiah::format($record->ledgerBalance()))
                            ->helperText('Harus selalu sama dengan saldo.'),
                        TextEntry::make('last_seen_at')->label('Terakhir main')->since()->placeholder('Belum pernah'),
                        TextEntry::make('created_at')->label('Terdaftar')->dateTime('d M Y, H:i'),
                    ]),
            ]);
    }
}
