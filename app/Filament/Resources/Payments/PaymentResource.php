<?php

namespace App\Filament\Resources\Payments;

use App\Domain\Billing\PaymentStatus;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Tables\PaymentsTable;
use App\Models\Payment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Operasional;

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Verifikasi Bayar';

    protected static ?string $modelLabel = 'pembayaran';

    protected static ?string $pluralModelLabel = 'pembayaran';

    public static function table(Table $table): Table
    {
        return PaymentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListPayments::route('/')];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Lencana berisi jumlah bukti yang MENUNGGU diperiksa — bukan jumlah
     * seluruh pembayaran. Bukti transfer yang menganggur berarti uang yang
     * belum diakui masuk; kasir harus melihatnya tanpa perlu membuka menunya.
     */
    public static function getNavigationBadge(): ?string
    {
        $menunggu = static::getModel()::query()
            ->where('status', PaymentStatus::AwaitingVerification)
            ->count();

        return $menunggu > 0 ? (string) $menunggu : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
