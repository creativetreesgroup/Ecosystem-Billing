<?php

namespace App\Filament\Resources\Discounts\Schemas;

use App\Domain\Discounts\DiscountSource;
use App\Domain\Discounts\DiscountTarget;
use App\Domain\Discounts\DiscountType;
use App\Domain\Discounts\VoucherCode;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class DiscountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Diskon')
                    ->columns(['md' => 2])
                    ->schema([
                        Select::make('source')
                            ->label('Sumber')
                            ->options(DiscountSource::class)
                            ->default(DiscountSource::Voucher->value)
                            ->live()
                            // default() hanya berlaku saat MEMBUAT. Mengubah
                            // promo lama menjadi voucher meninggalkan kodenya
                            // kosong, dan voucher tanpa kode adalah voucher
                            // yang tak bisa dipakai siapa pun di kios.
                            ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                                $source = $state instanceof DiscountSource ? $state->value : $state;

                                if ($source === DiscountSource::Voucher->value && blank($get('code'))) {
                                    $set('code', VoucherCode::generate());
                                }
                            })
                            ->required(),
                        // Kode hanya untuk voucher; disimpan huruf besar (mutator
                        // di model). Unik supaya satu kode tak menunjuk dua diskon.
                        // Kode di-uppercase oleh mutator di model Discount, jadi
                        // form tak perlu mengubahnya lagi. Promo (tanpa kode) tetap
                        // menyimpan null karena field disembunyikan & dikosongkan.
                        TextInput::make('code')
                            ->label('Kode voucher')
                            // Dibuatkan, bukan diketik: kode yang dikarang
                            // manusia selalu berasal dari nama promonya, dan
                            // kode yang bisa ditebak berarti kuotanya habis
                            // oleh orang yang tak pernah diberi voucher.
                            ->default(fn (): string => VoucherCode::generate())
                            ->readOnly()
                            ->prefixIcon(Heroicon::OutlinedTicket)
                            ->suffixAction(
                                Action::make('acakKode')
                                    ->label('Acak ulang')
                                    ->icon(Heroicon::OutlinedArrowPath)
                                    ->action(fn (Set $set) => $set('code', VoucherCode::generate())),
                            )
                            ->helperText('Dibuat acak. Tekan ikon di kanan untuk mengacak ulang.')
                            ->maxLength(30)
                            ->unique(ignoreRecord: true)
                            ->required(fn (Get $get): bool => $get('source') === DiscountSource::Voucher->value)
                            ->visible(fn (Get $get): bool => $get('source') === DiscountSource::Voucher->value)
                            ->dehydratedWhenHidden(),
                        TextInput::make('name')
                            ->label('Nama')
                            ->placeholder('mis. Diskon Pembukaan')
                            ->required()
                            ->columnSpanFull(),
                        Select::make('type')
                            ->label('Jenis potongan')
                            ->options(DiscountType::class)
                            ->default(DiscountType::Percentage->value)
                            ->live()
                            ->required(),
                        // maxValue mengikuti jenis: persen dibatasi 100, nominal
                        // tetap bebas. minValue 1 supaya diskon selalu berarti.
                        TextInput::make('value')
                            ->label('Nilai')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(fn (Get $get): ?int => $get('type') === DiscountType::Percentage->value ? 100 : null)
                            ->prefix(fn (Get $get): ?string => $get('type') === DiscountType::Fixed->value ? 'Rp' : null)
                            ->suffix(fn (Get $get): ?string => $get('type') === DiscountType::Percentage->value ? '%' : null)
                            ->required(),
                        CheckboxList::make('targets')
                            ->label('Berlaku untuk')
                            ->options(DiscountTarget::class)
                            ->required()
                            ->columnSpanFull(),
                    ]),
                Section::make('Batasan')
                    ->description('Kosongkan yang tak ingin dibatasi.')
                    ->columns(['md' => 2])
                    ->schema([
                        DateTimePicker::make('starts_at')
                            ->label('Mulai berlaku')
                            ->seconds(false),
                        DateTimePicker::make('ends_at')
                            ->label('Berakhir')
                            ->seconds(false)
                            ->after('starts_at'),
                        TextInput::make('max_uses')
                            ->label('Kuota total')
                            ->helperText('Total pemakaian semua pelanggan.')
                            ->numeric()
                            ->minValue(1),
                        TextInput::make('max_uses_per_customer')
                            ->label('Kuota per pelanggan')
                            ->numeric()
                            ->minValue(1),
                        TextInput::make('min_amount')
                            ->label('Minimal transaksi')
                            ->prefix('Rp')
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true),
                    ]),
            ]);
    }
}
