<?php

namespace App\Filament\Resources\Customers;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Filament\Resources\Customers\Schemas\CustomerInfolist;
use App\Filament\Resources\Customers\Tables\CustomersTable;
use App\Models\Customer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Member — pelanggan yang punya akun & saldo.
 *
 * Kasir memakainya untuk satu hal yang sering: memastikan seseorang benar-benar
 * terdaftar dan melihat saldonya, dengan mencari nama/nomor/nomor kartu. Karena
 * itu ia bisa dicari dari kolom pencarian global panel.
 *
 * Member TIDAK dibuat dari sini — mereka mendaftar sendiri di kios. Dan tidak
 * bisa dihapus: setiap member memegang buku besar saldo, dan menghapusnya
 * berarti menghapus jejak uang. Yang bisa dilakukan hanya menonaktifkan.
 */
class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Operasional;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Member';

    protected static ?string $modelLabel = 'member';

    protected static ?string $pluralModelLabel = 'Member';

    protected static ?string $recordTitleAttribute = 'name';

    /** Cari member dari kolom pencarian global: nama, nomor WA, atau nomor kartu. */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'phone', 'card_number'];
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails($record): array
    {
        return [
            'Nomor' => $record->phone,
            'Kartu' => $record->maskedCardNumber(),
        ];
    }

    /** Buku besar dihitung untuk kolom "jumlah transaksi" tanpa N+1. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('walletTransactions');
    }

    public static function form(Schema $schema): Schema
    {
        return CustomerForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CustomerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        // Tanpa 'create': member mendaftar sendiri di kios, tidak dibuat kasir.
        return [
            'index' => ListCustomers::route('/'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
